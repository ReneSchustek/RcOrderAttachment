<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Storefront\Page;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUpload;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUploadStore;
use Ruhrcoder\RcOrderAttachment\Storefront\Page\OrderAttachmentPageletLoader;
use Ruhrcoder\RcOrderAttachment\Storefront\Struct\AccountAttachmentsStruct;
use Ruhrcoder\RcOrderAttachment\Storefront\Struct\ConfirmPageUploadStruct;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Prüft, welche Daten der Pagelet-Loader für Bestätigungsseite und Bestellübersicht im Konto
 * liefert. Was er zurückgibt, bestimmt, ob das Template einen Upload-Bereich oder eine
 * Anhangsliste zeigt, auch eine leere.
 *
 * Die Plugin-Klassen sind echt, ersetzt sind nur `SystemConfigService`, `EntityRepository` und
 * die Sitzung.
 */
final class OrderAttachmentPageletLoaderTest extends TestCase
{
    /**
     * Ist das Plugin im Verkaufskanal aus, liefert der Loader `null`, und der Subscriber hängt
     * nichts an. Sonst zeigte das Template den Upload-Bereich trotzdem.
     */
    public function testLoadConfirmReturnsNullWhenDisabled(): void
    {
        $loader = new OrderAttachmentPageletLoader(
            $this->configProvider(['enabled' => false]),
            $this->pendingUploadStore(),
            $this->attachmentLoader([]),
        );

        $result = $loader->loadConfirm(new Request(), $this->salesChannelContext('sc-1'));

        self::assertNull($result);
    }

    /**
     * Der Speicher schlüsselt nach Token, der Struct verspricht aber eine Liste
     * (`list<PendingUpload>`). Mit den Tokens als Schlüssel würde daraus beim Serialisieren nach
     * JSON ein Objekt statt eines Arrays.
     */
    public function testLoadConfirmBuildsStructWithPendingUploadsAsList(): void
    {
        $store = $this->pendingUploadStore();
        $store->add($this->pendingUpload('tok-a'));
        $store->add($this->pendingUpload('tok-b'));

        $loader = new OrderAttachmentPageletLoader(
            $this->configProvider(['enabled' => true]),
            $store,
            $this->attachmentLoader([]),
        );

        $result = $loader->loadConfirm(new Request(), $this->salesChannelContext('sc-1'));

        self::assertInstanceOf(ConfirmPageUploadStruct::class, $result);
        self::assertCount(2, $result->getPendingUploads());
        self::assertSame([0, 1], array_keys($result->getPendingUploads()));
    }

    /**
     * Ohne Bestellungen gibt es nichts zu laden. Der Loader steigt vor der Datenbankabfrage aus;
     * der Test sieht davon nur das leere Ergebnis, nicht den ausbleibenden Aufruf.
     */
    public function testLoadAccountReturnsEmptyForNoOrders(): void
    {
        $loader = new OrderAttachmentPageletLoader(
            $this->configProvider(['enabled' => true]),
            $this->pendingUploadStore(),
            $this->attachmentLoader([]),
        );

        $result = $loader->loadAccount(new OrderCollection(), Context::createDefaultContext());

        self::assertSame([], $result);
    }

    /**
     * Die Anhänge aller Bestellungen kommen in einer Abfrage. Eine Bestellung ohne Anhang bekommt
     * keinen Eintrag, sonst zeigte das Template einen leeren Anhangsbereich.
     */
    public function testLoadAccountBuildsStructsAndSkipsEmptyOrders(): void
    {
        $orderIdA = '0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a0a';
        $orderIdB = '0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b0b';

        $orders = new OrderCollection([
            $this->order($orderIdA),
            $this->order($orderIdB),
        ]);

        $loader = new OrderAttachmentPageletLoader(
            $this->configProvider(['enabled' => true]),
            $this->pendingUploadStore(),
            $this->attachmentLoader([
                $this->attachment('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $orderIdA),
                // Für `$orderIdB` liefert die DAL nichts.
            ]),
        );

        $result = $loader->loadAccount($orders, Context::createDefaultContext());

        self::assertArrayHasKey($orderIdA, $result);
        self::assertInstanceOf(AccountAttachmentsStruct::class, $result[$orderIdA]);
        self::assertCount(1, $result[$orderIdA]->getAttachments());
        self::assertArrayNotHasKey($orderIdB, $result);
    }

    /**
     * Hat die Aufbewahrungsbereinigung alle Anhänge gelöscht, besteht die Bestellung weiter. Der
     * Loader erzeugt dann keine leeren Structs.
     */
    public function testLoadAccountReturnsEmptyWhenLoaderHasNoResults(): void
    {
        $orders = new OrderCollection([$this->order('cccccccccccccccccccccccccccccccc')]);

        $loader = new OrderAttachmentPageletLoader(
            $this->configProvider(['enabled' => true]),
            $this->pendingUploadStore(),
            $this->attachmentLoader([]),
        );

        $result = $loader->loadAccount($orders, Context::createDefaultContext());

        self::assertSame([], $result);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function configProvider(array $overrides): PluginConfigProvider
    {
        $defaults = [
            'enabled' => true,
            'required' => false,
            'maxFiles' => 5,
            'maxFileSizeKb' => 10240,
            'maxTotalSizeKb' => 40960,
            'allowedExtensions' => 'pdf,jpg',
            'orphanRetentionHours' => 24,
            'attachmentRetentionDays' => 180,
            'attachToConfirmationMail' => true,
            'stripExifFromImages' => true,
            'rateLimitPerMinute' => 30,
            'mailTemplateWhitelist' => 'order_confirmation_mail',
        ];
        $values = [...$defaults, ...$overrides];

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key) => $values[substr($key, \strlen(PluginConfigProvider::DOMAIN))] ?? null,
        );

        return new PluginConfigProvider($systemConfig);
    }

    private function pendingUploadStore(): PendingUploadStore
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        return new PendingUploadStore($stack);
    }

    /**
     * @param list<OrderAttachmentEntity> $entities
     */
    private function attachmentLoader(array $entities): OrderAttachmentLoader
    {
        $collection = new OrderAttachmentCollection($entities);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'rc_order_attachment',
                $collection->count(),
                $collection,
                null,
                $criteria,
                $context,
            ),
        );

        return new OrderAttachmentLoader($repository);
    }

    private function salesChannelContext(string $salesChannelId): SalesChannelContext
    {
        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getSalesChannelId')->willReturn($salesChannelId);

        return $context;
    }

    private function pendingUpload(string $token): PendingUpload
    {
        return new PendingUpload(
            token: $token,
            mediaId: 'media-' . $token,
            originalFileName: $token . '.pdf',
            mimeType: 'application/pdf',
            fileSize: 100,
            uploadedAt: 1_700_000_000,
        );
    }

    private function order(string $id): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId($id);
        $order->setUniqueIdentifier($id);

        return $order;
    }

    private function attachment(string $id, string $orderId): OrderAttachmentEntity
    {
        $entity = new OrderAttachmentEntity();
        $entity->setId($id);
        $entity->setUniqueIdentifier($id);
        $entity->setOrderId($orderId);
        $entity->setOrderVersionId('00000000000000000000000000000000');
        $entity->setMediaId('media-' . $id);
        $entity->setOriginalFileName($id . '.pdf');
        $entity->setMimeType('application/pdf');
        $entity->setFileSize(100);

        return $entity;
    }
}
