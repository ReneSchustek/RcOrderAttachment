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
 * Integration-Test gegen echte DAL — verifiziert Schreib-/Lese-Round-Trip,
 * Cascade-Delete bei Order-Drop, Version-Filter-Greifen.
 *
 * Voraussetzung: lebendes Shopware-Test-Bootstrap. Wird lokal über
 * `vendor/bin/phpunit --testsuite=Integration` oder gegen eine vollständige Installation via
 * `bin/phpunit` ausgeführt.
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
     * Was: Anhang via Manager persistieren und via Loader wieder lesen.
     * Warum: Integration-Smoke-Test — DAL-Schreib-/Leseweg muss durchlaufen,
     *        inkl. UUID-Generierung und Versions-Filter.
     * Erwartet: ID gesetzt, Filename round-trippt, `loadById` findet den
     *           gerade angelegten Datensatz.
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

        self::assertNotNull($attachment->getId());
        self::assertSame('drawing.pdf', $attachment->getOriginalFileName());

        $loaded = $this->loader->loadById($attachment->getId(), $this->context);
        self::assertSame($attachment->getId(), $loaded->getId());
    }

    /**
     * Was: Order löschen — verbundene Anhänge müssen via DB-FK-Cascade
     *      verschwinden.
     * Warum: DSGVO-Pflicht und Datenintegrität — Orphan-Anhänge ohne Order
     *        wären sowohl ein Speicher- als auch ein Auskunfts-Problem.
     * Erwartet: Nach `order.delete` ist der Anhang nicht mehr in der DAL.
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
        )->first();

        self::assertNull($afterDelete, 'Attachment muss per FK-Cascade gelöscht werden');
    }

    /**
     * Was: Filename mit U+202E (Right-to-Left-Override) durch den Manager
     *      und in die DAL.
     * Warum: End-to-End-Test der Sanitization — Unit-Tests prüfen den
     *        Sanitizer isoliert, aber wir wollen sichergehen, dass der
     *        Manager ihn auch wirklich aufruft, bevor er in die DAL schreibt.
     * Erwartet: Im DAL-Datensatz ist die Bidi-Sequenz nicht mehr enthalten.
     */
    public function testFilenameWithBidiOverrideIsSanitized(): void
    {
        $orderId = $this->createTestOrder();
        $mediaId = $this->createTestMedia();

        // U+202E (Right-to-Left Override) zwischen den Wörtern
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
     * Was: Anhang wird erst an die Live-Version, dann wird die Order mit einer
     *      neuen Version geforkt — der Filter im Loader muss nur den Live-Stand
     *      sehen.
     * Warum: Senior-Falle bei Shopware-Versioning — ohne `orderVersionId`-Filter
     *        sieht die Account-Page Draft-Anhänge oder Anhänge aus Admin-
     *        Edit-Sessions, die noch nicht „committed" sind.
     * Erwartet: Loader liefert nur die Live-Anhänge.
     */
    public function testOrderVersionFilterIgnoresDraftAttachments(): void
    {
        $orderId = $this->createTestOrder();
        $mediaId = $this->createTestMedia();

        // Anhang an die Live-Version
        $this->manager->attach($orderId, $mediaId, 'live.pdf', 'application/pdf', 100, $this->context);

        // Echte zweite Order-Version anlegen — die `createVersion`-API schreibt
        // einen `(order_id, version_id)`-Eintrag in `order`, damit der FK des
        // Draft-Anhangs greifen kann. Ohne diese Version würde der DAL-Insert
        // mit Integrity-Constraint-Violation abbrechen.
        $draftVersionId = static::repository('order.repository')->createVersion($orderId, $this->context);

        // Direkter Schreib-Test in Draft-Version umgehen den Manager — wir simulieren
        // einen Draft-Anhang via Repository direkt.
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
        // Erzeugt eine Test-Order via Shopware-Test-Fixture-Helpers.
        // Implementation hängt von der konkreten Shopware-Test-Bootstrap-Variante ab.
        // Alternativ: `OrderFixture` aus `shopware/core/Test/Fixtures` oder eigene Mini-Order.
        $orderId = Uuid::randomHex();
        // Die Rechnungsadresse muss im selben Schreibvorgang entstehen und von
        // `billingAddressId` referenziert werden. Eine lose UUID ohne passenden Datensatz
        // lässt den Fremdschlüssel greifen — der Test brach dann mit einem Fehler ab,
        // statt das zu prüfen, wofür er da ist.
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
            // Shopware 6.7+ verlangt CashRoundingConfig für Order-Entity (Pflichtfelder).
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
