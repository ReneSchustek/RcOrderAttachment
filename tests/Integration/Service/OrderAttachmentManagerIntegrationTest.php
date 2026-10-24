<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Integration\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentManagerInterface;
use Ruhrcoder\RcOrderAttachment\Tests\Integration\ContainerAccessTrait;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft Anlegen und Laden von Anhängen gegen die echte DAL: den Weg hin und zurück, das
 * Mitlöschen beim Löschen der Bestellung, die Bereinigung des Dateinamens und den Versionsfilter.
 * Mocks zeigen keinen dieser Punkte, weil sie an Fremdschlüsseln und Versionsspalten hängen.
 *
 * Läuft nur mit gebootetem Shopware-Testkernel, etwa über
 * `vendor/bin/phpunit --testsuite=Integration` in einer vollständigen Installation.
 */
final class OrderAttachmentManagerIntegrationTest extends TestCase
{
    use ContainerAccessTrait;
    use IntegrationTestBehaviour;

    private Context $context;

    private OrderAttachmentManagerInterface $manager;

    private OrderAttachmentLoader $loader;

    /**
     * @var EntityRepository<OrderAttachmentCollection>
     */
    private EntityRepository $attachmentRepository;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
        $this->manager = static::service(OrderAttachmentManagerInterface::class);
        $this->loader = static::service(OrderAttachmentLoader::class);
        $attachmentRepository = static::repository('rc_order_attachment.repository');
        /** @var EntityRepository<OrderAttachmentCollection> $attachmentRepository */
        $this->attachmentRepository = $attachmentRepository;
    }

    /**
     * Rauchprobe für den Schreib- und Leseweg samt ID-Vergabe und Versionsfilter.
     */
    public function testAttachAndLoadRoundTrip(): void
    {
        $orderId = $this->createTestOrder();
        $mediaId = $this->createTestMedia();

        $attachment = $this->manager->attach(
            orderId: $orderId,
            mediaId: $mediaId,
            originalFileName: 'drawing.pdf',
            mimeType: 'application/pdf',
            fileSize: 12345,
            context: $this->context,
        );

        self::assertNotSame('', $attachment->getId());
        self::assertSame('drawing.pdf', $attachment->getOriginalFileName());

        $loaded = $this->loader->loadById($attachment->getId(), $this->context);
        self::assertSame($attachment->getId(), $loaded->getId());
    }

    /**
     * Anhänge ohne Bestellung wären ein Speicher- und ein DSGVO-Problem, deshalb muss der
     * Fremdschlüssel mit Kaskade sie beim Löschen der Bestellung mitnehmen.
     */
    public function testCascadeDeleteOnOrderDrop(): void
    {
        $orderId = $this->createTestOrder();
        $mediaId = $this->createTestMedia();

        $attachment = $this->manager->attach(
            orderId: $orderId,
            mediaId: $mediaId,
            originalFileName: 'drawing.pdf',
            mimeType: 'application/pdf',
            fileSize: 100,
            context: $this->context,
        );

        static::repository('order.repository')->delete(
            [['id' => $orderId]],
            $this->context,
        );

        $afterDelete = $this->attachmentRepository->search(
            new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria([$attachment->getId()]),
            $this->context,
        )->getEntities()->first();

        self::assertNull($afterDelete, 'Attachment muss per FK-Cascade gelöscht werden');
    }

    /**
     * Die Unit-Tests prüfen den Bereiniger für sich. Erst hier zeigt sich, dass der Manager ihn
     * aufruft, bevor er schreibt; sonst gäbe sich `….exe` in der Verwaltung als `….jpg` aus.
     */
    public function testFilenameWithBidiOverrideIsSanitized(): void
    {
        $orderId = $this->createTestOrder();
        $mediaId = $this->createTestMedia();

        // U+202E kehrt die Leserichtung um, angezeigt wird daraus „cuteexe.jpg".
        $attackName = "cute\xE2\x80\xAEgpj.exe";

        $attachment = $this->manager->attach(
            orderId: $orderId,
            mediaId: $mediaId,
            originalFileName: $attackName,
            mimeType: 'image/jpeg',
            fileSize: 100,
            context: $this->context,
        );

        self::assertStringNotContainsString("\xE2\x80\xAE", $attachment->getOriginalFileName());
    }

    /**
     * Shopware legt beim Bearbeiten einer Bestellung in der Verwaltung eine eigene Version an.
     * Ohne Filter auf `orderVersionId` zeigte die Kontoseite Anhänge aus solchen Entwürfen, die
     * noch nicht übernommen sind.
     */
    public function testOrderVersionFilterIgnoresDraftAttachments(): void
    {
        $orderId = $this->createTestOrder();
        $mediaId = $this->createTestMedia();

        // Anhang an die Live-Version
        $this->manager->attach($orderId, $mediaId, 'live.pdf', 'application/pdf', 100, $this->context);

        // `createVersion()` schreibt die Zeile `(order_id, version_id)` in `order`, auf die der
        // Fremdschlüssel des Entwurfsanhangs zeigt. Ohne sie bricht das Einfügen mit einer
        // Verletzung der Integritätsbedingung ab.
        $draftVersionId = static::repository('order.repository')->createVersion($orderId, $this->context);

        // Der Manager schreibt an die Version, die der Kontext sieht, hier also an die
        // Live-Version. Der Entwurfsanhang geht deshalb direkt über das Repository.
        $draftAttachmentId = Uuid::randomHex();
        $draftMediaId = $this->createTestMedia();
        $this->attachmentRepository->create([[
            'id' => $draftAttachmentId,
            'orderId' => $orderId,
            'orderVersionId' => $draftVersionId,
            'mediaId' => $draftMediaId,
            'originalFileName' => 'draft.pdf',
            'mimeType' => 'application/pdf',
            'fileSize' => 100,
        ]], $this->context);

        $loaded = $this->loader->loadForOrder($orderId, $this->context);

        self::assertSame(1, $loaded->count(), 'Nur Live-Version-Anhänge dürfen geladen werden');
        self::assertSame('live.pdf', $loaded->first()?->getOriginalFileName());
    }

    private function createTestOrder(): string
    {
        $orderId = Uuid::randomHex();
        // Die Rechnungsadresse muss im selben Schreibvorgang entstehen und von
        // `billingAddressId` referenziert werden. Mit einer losen UUID ohne passenden
        // Datensatz greift der Fremdschlüssel, und der Test bricht ab, bevor er prüft,
        // wofür er da ist.
        $billingAddressId = Uuid::randomHex();
        static::repository('order.repository')->create([[
            'id' => $orderId,
            'orderNumber' => 'TEST-' . $orderId,
            'orderDateTime' => '2026-05-20 10:00:00',
            'currencyId' => Defaults::CURRENCY,
            'currencyFactor' => 1.0,
            'salesChannelId' => $this->getValidSalesChannelId(),
            'stateId' => $this->getOrderStateId('open'),
            'price' => [
                'netPrice' => 100,
                'totalPrice' => 119,
                'positionPrice' => 100,
                'rawTotal' => 119,
                'taxStatus' => 'gross',
                'calculatedTaxes' => [],
                'taxRules' => [],
            ],
            'shippingCosts' => [
                'unitPrice' => 0,
                'totalPrice' => 0,
                'quantity' => 1,
                'calculatedTaxes' => [],
                'taxRules' => [],
            ],
            // Shopware 6.7 verlangt beide Rundungsangaben als Pflichtfelder der Bestellung.
            'totalRounding' => [
                'decimals' => 2,
                'interval' => 0.01,
                'roundForNet' => true,
            ],
            'itemRounding' => [
                'decimals' => 2,
                'interval' => 0.01,
                'roundForNet' => true,
            ],
            'orderCustomer' => [
                'email' => 'integration-test@example.com',
                'firstName' => 'Test',
                'lastName' => 'Customer',
                'salutationId' => $this->getSalutationId(),
            ],
            'billingAddressId' => $billingAddressId,
            'addresses' => [[
                'id' => $billingAddressId,
                'salutationId' => $this->getSalutationId(),
                'firstName' => 'Test',
                'lastName' => 'Customer',
                'street' => 'Teststrasse 1',
                'zipcode' => '12345',
                'city' => 'Testort',
                'countryId' => $this->getCountryId(),
            ]],
        ]], $this->context);

        return $orderId;
    }

    private function createTestMedia(): string
    {
        $mediaId = Uuid::randomHex();
        static::repository('media.repository')->create([[
            'id' => $mediaId,
            'private' => true,
        ]], $this->context);

        return $mediaId;
    }

    private function getCountryId(): string
    {
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->setLimit(1);

        $id = static::repository('country.repository')->searchIds($criteria, $this->context)->firstId();
        self::assertNotNull($id, 'Mindestens ein Land muss existieren');

        return $id;
    }

    private function getValidSalesChannelId(): string
    {
        $repo = static::repository('sales_channel.repository');
        $id = $repo->searchIds(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria(), $this->context)->firstId();
        self::assertNotNull($id, 'Mindestens ein Sales-Channel muss existieren');

        return $id;
    }

    private function getOrderStateId(string $technicalName): string
    {
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('technicalName', $technicalName));
        $criteria->setLimit(1);

        return (string) static::repository('state_machine_state.repository')
            ->searchIds($criteria, $this->context)->firstId();
    }

    private function getSalutationId(): string
    {
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->setLimit(1);

        return (string) static::repository('salutation.repository')
            ->searchIds($criteria, $this->context)->firstId();
    }
}
