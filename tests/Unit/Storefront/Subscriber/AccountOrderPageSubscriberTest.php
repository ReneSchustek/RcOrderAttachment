<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Storefront\Page\OrderAttachmentPageletLoaderInterface;
use Ruhrcoder\RcOrderAttachment\Storefront\Struct\AccountAttachmentsStruct;
use Ruhrcoder\RcOrderAttachment\Storefront\Subscriber\AccountOrderPageSubscriber;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Account\Order\AccountOrderPage;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoadedEvent;

/**
 * Prüft den Subscriber der Bestellübersicht im Kundenkonto: Er hört auf das richtige Ereignis und
 * hängt die Anhänge je Bestellung an. Über diese Seite sieht der Kunde seine Dateien ein
 * (Auskunft nach Art. 15 DSGVO).
 */
final class AccountOrderPageSubscriberTest extends TestCase
{
    /**
     * Mit falscher Ereignisklasse oder falschem Methodennamen liefe der Subscriber nie, und kein
     * Fehler wiese darauf hin.
     */
    public function testSubscribesToAccountOrderPageLoadedEvent(): void
    {
        $events = AccountOrderPageSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(AccountOrderPageLoadedEvent::class, $events);
        self::assertSame('onAccountOrderPageLoaded', $events[AccountOrderPageLoadedEvent::class]);
    }

    /**
     * Der häufige Fall ohne Anhänge: Hinge trotzdem eine Erweiterung an, zeigte das Template einen
     * leeren Anhangsbereich.
     */
    public function testSkipsWhenLoaderReturnsNothing(): void
    {
        $loader = $this->createMock(OrderAttachmentPageletLoaderInterface::class);
        $loader->method('loadAccount')->willReturn([]);

        $orderA = $this->order('order-a');
        $subscriber = new AccountOrderPageSubscriber($loader);
        $subscriber->onAccountOrderPageLoaded($this->event([$orderA]));

        self::assertNull($orderA->getExtension(AccountOrderPageSubscriber::EXTENSION_NAME));
    }

    /**
     * Nur Bestellungen mit Treffer bekommen den Struct, die übrigen bleiben ohne leeren Bereich.
     */
    public function testAttachesExtensionForMatchingOrdersOnly(): void
    {
        $orderA = $this->order('order-a');
        $orderB = $this->order('order-b');

        $structA = new AccountAttachmentsStruct(new OrderAttachmentCollection());

        $loader = $this->createMock(OrderAttachmentPageletLoaderInterface::class);
        $loader->expects(self::once())
            ->method('loadAccount')
            ->willReturn(['order-a' => $structA]);

        $subscriber = new AccountOrderPageSubscriber($loader);
        $subscriber->onAccountOrderPageLoaded($this->event([$orderA, $orderB]));

        self::assertSame($structA, $orderA->getExtension(AccountOrderPageSubscriber::EXTENSION_NAME));
        self::assertNull($orderB->getExtension(AccountOrderPageSubscriber::EXTENSION_NAME));
    }

    /**
     * @param list<OrderEntity> $orders
     */
    private function event(array $orders): AccountOrderPageLoadedEvent
    {
        $collection = new OrderCollection($orders);
        $searchResult = new EntitySearchResult(
            'order',
            $collection->count(),
            $collection,
            null,
            new Criteria(),
            Context::createDefaultContext(),
        );

        $page = new AccountOrderPage();
        $page->setOrders($searchResult);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return new AccountOrderPageLoadedEvent($page, $salesChannelContext, new \Symfony\Component\HttpFoundation\Request());
    }

    private function order(string $id): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId($id);
        $order->setUniqueIdentifier($id);

        return $order;
    }
}
