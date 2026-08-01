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
 * Senior-saubere Tests gegen den Page-Loader: keine Mocks finaler Plugin-Klassen,
 * stattdessen echte Instanzen mit gestubbten Shopware-Boundaries
 * (`SystemConfigService`, `EntityRepository`, `Session`).
 */
final class OrderAttachmentPageletLoaderTest extends TestCase
{
    /**
     * Was: `loadConfirm` mit deaktiviertem Plugin.
     * Warum: Der Subscriber darf für inaktive Sales-Channels keine Upload-Extension
     *        an die Confirm-Page hängen — sonst rendert Twig die Upload-Sektion
     *        trotz Deaktivierung.
     * Erwartet: `null` als Rückgabe; der Subscriber überspringt das `addExtension`.
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
     * Was: `loadConfirm` mit aktivem Plugin und zwei Pending-Uploads in der Session.
     * Warum: Die Token-Keys (`tok-a`, `tok-b`) aus dem Store müssen verworfen werden,
     *        weil das Twig-Template eine sequentielle Liste (0, 1, …) erwartet —
     *        sonst wirft `for`-Iteration in HEEDS-Templates auf.
     * Erwartet: `ConfirmPageUploadStruct` mit zwei Uploads und Listen-Indizes `[0, 1]`.
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
        // Token-Keys aus dem Store werden verworfen — Twig erwartet eine Liste.
        self::assertSame([0, 1], array_keys($result->getPendingUploads()));
    }

    /**
     * Was: `loadAccount` für eine leere Order-Collection.
     * Warum: Kunden ohne Bestellhistorie dürfen keinen DAL-Roundtrip auslösen —
     *        Performance-Pflicht im Storefront-Render-Pfad.
     * Erwartet: leeres Array, kein Aufruf des `OrderAttachmentLoader::loadForOrderIds`.
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
     * Was: `loadAccount` mit zwei Orders, von denen nur eine Anhänge besitzt.
     * Warum: Account-Order-Page (DSGVO Art. 15) muss N+1-frei arbeiten und darf
     *        Orders ohne Anhänge nicht mit leerer Extension befüllen — sonst
     *        rendert das Template eine leere „Anhänge"-Sektion.
     * Erwartet: Ergebnis enthält nur die Order mit Treffer; die zweite fehlt.
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
                // orderIdB liefert die DAL gar nicht zurück
            ]),
        );

        $result = $loader->loadAccount($orders, Context::createDefaultContext());

        self::assertArrayHasKey($orderIdA, $result);
        self::assertInstanceOf(AccountAttachmentsStruct::class, $result[$orderIdA]);
        self::assertCount(1, $result[$orderIdA]->getAttachments());
        self::assertArrayNotHasKey($orderIdB, $result);
    }

    /**
     * Was: `loadAccount` wenn der DAL-Loader gar keine Treffer liefert.
     * Warum: Edge-Case bei Retention-Cleanup — alle Anhänge sind bereits gelöscht,
     *        Order existiert noch. Der Loader darf keine leeren Structs erzeugen.
     * Erwartet: leeres Array.
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
