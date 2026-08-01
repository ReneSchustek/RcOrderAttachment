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
 * Unit-Tests für den Event-Adapter zur Account-Order-Page. Verifiziert, dass der
 * Subscriber das richtige Event abonniert und die Pagelet-Daten vom Loader
 * korrekt pro Order an die Twig-Extension hängt (DSGVO Art. 15).
 */
final class AccountOrderPageSubscriberTest extends TestCase
{
    /**
     * Was: Statische Event-Subscription des Subscribers.
     * Warum: Verbindlicher Vertrag mit dem Shopware-EventDispatcher — falsche
     *        Event-Klasse oder Methode würde dafür sorgen, dass der Subscriber
     *        nie aufgerufen wird und Kunden ihre Anhänge nicht sehen.
     * Erwartet: `AccountOrderPageLoadedEvent` ist registriert auf
     *           `onAccountOrderPageLoaded`.
     */
    public function testSubscribesToAccountOrderPageLoadedEvent(): void
    {
        $events = AccountOrderPageSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(AccountOrderPageLoadedEvent::class, $events);
        self::assertSame('onAccountOrderPageLoaded', $events[AccountOrderPageLoadedEvent::class]);
    }

    /**
     * Was: Loader liefert leeres Ergebnis-Array (keine Anhänge gefunden).
     * Warum: Häufiger Fall: Kunden ohne Anhänge — der Subscriber darf KEINE
     *        Extension hängen, sonst rendert das Template eine leere
     *        „Anhänge"-Sektion.
     * Erwartet: Order hat keine `rcOrderAttachmentList`-Extension.
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
     * Was: Loader liefert Treffer nur für `order-a`; `order-b` ist nicht im Ergebnis.
     * Warum: Selektives Anhängen verhindert leere Sektionen bei Orders ohne
     *        Anhänge (UX) und reduziert Render-Aufwand.
     * Erwartet: `order-a` bekommt das Struct, `order-b` bleibt ohne Extension.
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
