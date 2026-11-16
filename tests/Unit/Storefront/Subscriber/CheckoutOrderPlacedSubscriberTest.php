<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Message\RetryOrderAttachmentLinkMessage;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentManagerInterface;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUpload;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUploadStore;
use Ruhrcoder\RcOrderAttachment\Storefront\Subscriber\CheckoutOrderPlacedSubscriber;
use RuntimeException;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPage;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Prüft, wie die offenen Uploads beim Bestellabschluss zu Anhängen werden: Rang -500 nach dem
 * Aufteilen des Warenkorbs, derselbe Snapshot für jede Teilbestellung, sofortiges Leeren der
 * Sitzung, Trennung der Anfragen in langlebigen Workern über `ResetInterface` und ein
 * nachgeplanter Versuch, wenn ein Anhang scheitert. Jeder dieser Punkte verhindert, dass eine
 * Kundendatei verloren geht oder bei einer fremden Bestellung landet.
 */
final class CheckoutOrderPlacedSubscriberTest extends TestCase
{
    // Ein beliebiger fester Zeitstempel (November 2023), damit der Test nicht von der Uhr abhängt.
    private const FIXED_UPLOADED_AT = 1_700_000_000;

    /**
     * Mit Rang -500 läuft der Subscriber nach `RcCartSplitter`, sodass alle Teilbestellungen
     * existieren, bevor die Anhänge angeheftet werden. Den Wert legt das Protokoll für das
     * Zusammenspiel der Plugins fest.
     */
    public function testSubscribesToOrderPlacedAndFinishPageWithCorrectPriority(): void
    {
        $events = CheckoutOrderPlacedSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(CheckoutOrderPlacedEvent::class, $events);
        self::assertSame(['onOrderPlaced', -500], $events[CheckoutOrderPlacedEvent::class]);

        // Die Abschlussseite läuft mit Rang 0. Die -500 regeln nur die Reihenfolge gegenüber
        // RcCartSplitter beim Bestellabschluss, nicht das wiederholbare Aufräumen danach.
        self::assertArrayHasKey(CheckoutFinishPageLoadedEvent::class, $events);
        self::assertSame(['onFinishPageLoaded', 0], $events[CheckoutFinishPageLoadedEvent::class]);
    }

    /**
     * Das Sicherheitsnetz für die Rückkehr von einem externen Zahlungsanbieter, bei der die
     * Abschlussseite ohne vorheriges `checkout.order.placed` in dieser Anfrage erreicht wird.
     * Bliebe ein offener Upload stehen, zeigte ihn die Bestätigungsseite des nächsten Warenkorbs
     * samt Token zum Entfernen, und ein Klick risse über `ON DELETE CASCADE` den Anhang der
     * abgeschlossenen Bestellung mit.
     */
    public function testFinishPageClearsPendingUploadsWithoutPriorOrderPlaced(): void
    {
        $store = $this->newStore();
        $store->add(new PendingUpload(
            token: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            mediaId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            originalFileName: 'zeichnung.pdf',
            mimeType: 'application/pdf',
            fileSize: 1234,
            uploadedAt: self::FIXED_UPLOADED_AT,
        ));

        $subscriber = new CheckoutOrderPlacedSubscriber(
            $this->createMock(OrderAttachmentManagerInterface::class),
            $store,
            $this->permissiveBus(),
            new NullLogger(),
        );

        $subscriber->onFinishPageLoaded($this->finishPageEvent('cccccccccccccccccccccccccccccccc'));

        self::assertSame(0, $store->count(), 'ein stehengebliebener Pending-Upload ist der Datenverlust-Pfad');
    }

    /**
     * Lädt der Kunde die Abschlussseite neu, darf das zweite Aufräumen weder werfen noch etwas
     * anderes anfassen.
     */
    public function testFinishPageIsIdempotent(): void
    {
        $store = $this->newStore();
        $subscriber = new CheckoutOrderPlacedSubscriber(
            $this->createMock(OrderAttachmentManagerInterface::class),
            $store,
            $this->permissiveBus(),
            new NullLogger(),
        );

        $event = $this->finishPageEvent('dddddddddddddddddddddddddddddddd');
        $subscriber->onFinishPageLoaded($event);
        $subscriber->onFinishPageLoaded($event);

        self::assertSame(0, $store->count());
    }

    /**
     * Der Regelfall: Jeder offene Upload wird mit der gerade angelegten Bestellung verknüpft,
     * sonst gingen die Dateien nach dem Abschluss verloren.
     */
    public function testAttachesPendingUploadsAtOrderPlaced(): void
    {
        $store = $this->newStore();
        $store->add(new PendingUpload('t1', 'media-1', 'a.pdf', 'application/pdf', 100, self::FIXED_UPLOADED_AT));
        $store->add(new PendingUpload('t2', 'media-2', 'b.pdf', 'application/pdf', 200, self::FIXED_UPLOADED_AT));

        $manager = $this->createMock(OrderAttachmentManagerInterface::class);
        $manager->expects(self::exactly(2))
            ->method('attach')
            ->willReturn(new OrderAttachmentEntity());

        $subscriber = new CheckoutOrderPlacedSubscriber($manager, $store, $this->permissiveBus(), new NullLogger());
        $subscriber->onOrderPlaced($this->event('order-1'));
    }

    /**
     * Die Sitzung wird schon in `onOrderPlaced()` geleert, nicht erst auf der Abschlussseite.
     * Bestellabschluss (`POST /checkout/order`) und Abschlussseite (`GET /checkout/finish`) sind
     * getrennte Anfragen, und ein Merker aus der ersten ist in der zweiten nicht mehr gesetzt
     * (neue Instanz unter PHP-FPM, `kernel.reset` im Worker). Hinge das Leeren an so einem Merker,
     * bliebe die Datei offen, und ein Klick auf „Entfernen" im nächsten Warenkorb risse über
     * `ON DELETE CASCADE` den Anhang der abgeschlossenen Bestellung mit.
     */
    public function testPendingStoreIsClearedRightAfterOrderPlaced(): void
    {
        $store = $this->newStore();
        $store->add(new PendingUpload('t1', 'media-1', 'a.pdf', 'application/pdf', 100, self::FIXED_UPLOADED_AT));

        $manager = $this->createMock(OrderAttachmentManagerInterface::class);
        $manager->method('attach')->willReturn(new OrderAttachmentEntity());

        $subscriber = new CheckoutOrderPlacedSubscriber($manager, $store, $this->permissiveBus(), new NullLogger());
        $subscriber->onOrderPlaced($this->event('order-1'));

        self::assertSame(0, $store->count(), 'Pending-Uploads müssen mit dem Order-Placement aus der Session verschwinden');
    }

    /**
     * Das frühe Leeren darf das Aufteilen des Warenkorbs nicht stören. Quelle für alle
     * Teilbestellungen ist der Snapshot im Arbeitsspeicher, nicht die Liste in der Sitzung.
     */
    public function testCartSplitStillAttachesAfterStoreWasCleared(): void
    {
        $store = $this->newStore();
        $store->add(new PendingUpload('t1', 'media-1', 'a.pdf', 'application/pdf', 100, self::FIXED_UPLOADED_AT));

        $manager = $this->createMock(OrderAttachmentManagerInterface::class);
        $manager->expects(self::exactly(2))->method('attach')->willReturn(new OrderAttachmentEntity());

        $subscriber = new CheckoutOrderPlacedSubscriber($manager, $store, $this->permissiveBus(), new NullLogger());
        $subscriber->onOrderPlaced($this->event('order-1'));
        self::assertSame(0, $store->count());

        // Die zweite Teilbestellung derselben Anfrage speist sich aus dem Snapshot.
        $subscriber->onOrderPlaced($this->event('order-2'));
    }

    /**
     * `RcCartSplitter` zerlegt eine Bestellung in Teilbestellungen, und für jede kommt das
     * Ereignis. Beide bekommen denselben Snapshot, sonst bliebe die zweite ohne Anhang.
     */
    public function testReusesSnapshotAcrossMultipleOrdersForCartSplit(): void
    {
        $store = $this->newStore();
        $store->add(new PendingUpload('t1', 'media-1', 'a.pdf', 'application/pdf', 100, self::FIXED_UPLOADED_AT));

        $manager = $this->createMock(OrderAttachmentManagerInterface::class);
        // Ein Anhang je Teilbestellung.
        $manager->expects(self::exactly(2))
            ->method('attach')
            ->willReturn(new OrderAttachmentEntity());

        $subscriber = new CheckoutOrderPlacedSubscriber($manager, $store, $this->permissiveBus(), new NullLogger());
        $subscriber->onOrderPlaced($this->event('order-1'));
        $subscriber->onOrderPlaced($this->event('order-2'));
    }

    /**
     * In langlebigen Workern (Swoole, RoadRunner, FrankenPHP) überlebt der Subscriber die
     * Anfrage. Ohne `reset()` hinge die Datei von Kunde A auch an der Bestellung von Kunde B,
     * ein Datenleck zwischen Kunden.
     */
    public function testResetClearsSnapshotForWorkerModeIsolation(): void
    {
        $store = $this->newStore();
        $store->add(new PendingUpload('t1', 'media-1', 'a.pdf', 'application/pdf', 100, self::FIXED_UPLOADED_AT));

        $manager = $this->createMock(OrderAttachmentManagerInterface::class);
        // Ein Anhang für Kunde A; Kunde B kommt nach `reset()` mit leerem Speicher und bekommt keinen.
        $manager->expects(self::once())
            ->method('attach')
            ->willReturn(new OrderAttachmentEntity());

        $subscriber = new CheckoutOrderPlacedSubscriber($manager, $store, $this->permissiveBus(), new NullLogger());
        $subscriber->onOrderPlaced($this->event('order-customer-a'));

        // Im Worker ruft der Kernel `reset()` am Ende jeder Anfrage auf.
        $subscriber->reset();

        $store->clear();
        $subscriber->onOrderPlaced($this->event('order-customer-b'));
    }

    /**
     * Die Bestellung ist zu diesem Zeitpunkt schon angelegt. Ein einzelner Schreibfehler wird
     * protokolliert und hält weder die übrigen Anhänge noch die Abschlussseite auf.
     */
    public function testAttachFailureDoesNotBreakOtherUploads(): void
    {
        $store = $this->newStore();
        $store->add(new PendingUpload('t1', 'media-1', 'a.pdf', 'application/pdf', 100, self::FIXED_UPLOADED_AT));
        $store->add(new PendingUpload('t2', 'media-2', 'b.pdf', 'application/pdf', 200, self::FIXED_UPLOADED_AT));

        $manager = $this->createMock(OrderAttachmentManagerInterface::class);
        $manager->expects(self::exactly(2))
            ->method('attach')
            ->willReturnOnConsecutiveCalls(
                self::throwException(new RuntimeException('boom')),
                new OrderAttachmentEntity(),
            );

        $subscriber = new CheckoutOrderPlacedSubscriber($manager, $store, $this->permissiveBus(), new NullLogger());
        $subscriber->onOrderPlaced($this->event('order-1'));
    }

    /**
     * Das Medium existiert nach einem gescheiterten Verknüpfen weiter. Ohne nachgeplanten
     * Versuch über die Warteschlange wäre es still für die Bestellung verloren; die Nachricht
     * trägt nur das gescheiterte Medium.
     */
    public function testSchedulesLinkRetryWhenAttachFails(): void
    {
        $store = $this->newStore();
        $store->add(new PendingUpload('t1', 'media-1', 'a.pdf', 'application/pdf', 100, self::FIXED_UPLOADED_AT));
        $store->add(new PendingUpload('t2', 'media-2', 'b.pdf', 'application/pdf', 200, self::FIXED_UPLOADED_AT));

        $manager = $this->createMock(OrderAttachmentManagerInterface::class);
        $manager->method('attach')->willReturnOnConsecutiveCalls(
            self::throwException(new RuntimeException('boom')),
            new OrderAttachmentEntity(),
        );

        $dispatched = null;
        $bus = $this->createMock(MessageBusInterface::class);
        // Die eine verknüpfte Datei geht an den Shop, die andere kommt in den Wiederholversuch.
        $bus->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                if ($message instanceof RetryOrderAttachmentLinkMessage) {
                    $dispatched = $message;
                }

                return new Envelope($message);
            },
        );

        $subscriber = new CheckoutOrderPlacedSubscriber($manager, $store, $bus, new NullLogger());
        $subscriber->onOrderPlaced($this->event('order-1'));

        self::assertInstanceOf(RetryOrderAttachmentLinkMessage::class, $dispatched);
        self::assertSame('order-1', $dispatched->orderId);
        self::assertCount(1, $dispatched->uploads);
        self::assertSame('media-1', $dispatched->uploads[0]['mediaId']);
    }

    private function permissiveBus(): MessageBusInterface
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static fn (object $message): Envelope => new Envelope($message),
        );

        return $bus;
    }

    private function newStore(): PendingUploadStore
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        return new PendingUploadStore($stack);
    }

    private function event(string $orderId): CheckoutOrderPlacedEvent
    {
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setUniqueIdentifier($orderId);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return new CheckoutOrderPlacedEvent($salesChannelContext, $order);
    }

    private function finishPageEvent(string $orderId): CheckoutFinishPageLoadedEvent
    {
        $order = new OrderEntity();
        $order->setId($orderId);
        $order->setUniqueIdentifier($orderId);

        $page = new CheckoutFinishPage();
        $page->setOrder($order);

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getContext')->willReturn(Context::createDefaultContext());

        return new CheckoutFinishPageLoadedEvent($page, $salesChannelContext, Request::create('/checkout/finish'));
    }
}
