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
 * Unit-Tests für den Order-Placed-Subscriber. Verifiziert vier kritische
 * Verhaltensaspekte: Event-Registrierung mit Priority -500 (nach Cart-Splitter),
 * Snapshot-Reuse bei Multi-Order-Cart-Split, Worker-Mode-Isolation via
 * `ResetInterface`, und Fail-Soft beim Anhang-Versagen.
 */
final class CheckoutOrderPlacedSubscriberTest extends TestCase
{
    private const FIXED_UPLOADED_AT = 1_700_000_000;

    /**
     * Was: Event-Subscription mit Priority `-500`.
     * Warum: Priority muss nach `RcCartSplitter` laufen, damit alle Teil-Orders
     *        existieren, bevor die Anhänge angeheftet werden — Plugin-Interaktion
     *        gemäß dem Plugin-Interaktions-Protokoll.
     * Erwartet: Event `CheckoutOrderPlacedEvent` ist mit `['onOrderPlaced', -500]`
     *           registriert.
     */
    public function testSubscribesToOrderPlacedAndFinishPageWithCorrectPriority(): void
    {
        $events = CheckoutOrderPlacedSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(CheckoutOrderPlacedEvent::class, $events);
        self::assertSame(['onOrderPlaced', -500], $events[CheckoutOrderPlacedEvent::class]);

        // Die Finish-Page hängt bewusst auf Priorität 0: Das −500-Protokoll gilt für das
        // Order-Placement gegenüber RcCartSplitter, nicht für das idempotente Aufräumen danach.
        self::assertArrayHasKey(CheckoutFinishPageLoadedEvent::class, $events);
        self::assertSame(['onFinishPageLoaded', 0], $events[CheckoutFinishPageLoadedEvent::class]);
    }

    /**
     * Was: Die Finish-Page wird erreicht, ohne dass zuvor ein `checkout.order.placed` lief —
     *      der Fall bei Rückkehr von einem externen Zahlungsanbieter.
     * Warum: Das Sicherheitsnetz. Bleibt ein Pending-Upload in der Session stehen, zeigt ihn die
     *        Confirm-Page des nächsten Warenkorbs samt gültigem Remove-Token; ein Klick löscht
     *        das Media und reißt über `ON DELETE CASCADE` den Anhang der bereits
     *        abgeschlossenen Bestellung mit — der Datenverlust, den der Test unten für den
     *        regulären Bestellweg absichert.
     * Erwartet: Die Session ist danach leer.
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
     * Was: Zweimaliger Aufruf der Finish-Page (F5).
     * Warum: `clear()` muss idempotent sein — ein Neuladen darf keinen Fehler werfen und nichts
     *        anderes anfassen.
     * Erwartet: Kein Fehler, Session bleibt leer.
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
     * Was: Order-Placed-Event mit zwei Pending-Uploads in der Session.
     * Warum: Standard-Pfad — beide Anhänge müssen mit der gerade angelegten
     *        Order verbunden werden. Sonst gehen die hochgeladenen Dateien nach
     *        Order-Abschluss verloren.
     * Erwartet: Zwei `attach`-Aufrufe am Manager (einer pro Pending-Upload).
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
     * Was: Nach dem Order-Placement ist die Pending-Liste in der Session leer.
     * Warum: REGRESSION mit Datenverlust. Geleert wurde vorher erst in
     *        `onFinishPageLoaded()` — hinter einem `snapshotTaken`-Guard. Order-Placement
     *        (`POST /checkout/order`) und Finish-Page (`GET /checkout/finish`) sind aber
     *        zwei getrennte Requests; im zweiten war das Flag immer `false` (neue
     *        Instanz bei PHP-FPM, `kernel.reset` im Worker-Mode). `clear()` war toter Code.
     *        Folge: Die Datei blieb „pending", die Confirm-Page des nächsten Warenkorbs
     *        zeigte sie mit gültigem Remove-Token. Ein Klick auf „Entfernen" löschte das
     *        Media, und der FK `ON DELETE CASCADE` riss den Anhang der bereits
     *        abgeschlossenen Bestellung mit.
     * Erwartet: `count() === 0` unmittelbar nach `onOrderPlaced`.
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
     * Was: Cart-Split — zweites `CheckoutOrderPlacedEvent` im selben Request,
     *      nachdem der Store bereits geleert wurde.
     * Warum: Das Leeren darf die Mehrfach-Order-Fähigkeit nicht kaputt machen. Der
     *        Snapshot lebt im Arbeitsspeicher und ist die Quelle für alle Teil-Orders;
     *        die Session-Liste ist es nicht.
     * Erwartet: Auch die zweite Teil-Order bekommt ihren Anhang.
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

        // Zweite Teil-Order desselben Requests — speist sich aus dem Snapshot.
        $subscriber->onOrderPlaced($this->event('order-2'));
    }

    /**
     * Was: Zwei `CheckoutOrderPlacedEvent` hintereinander (Cart-Split-Szenario).
     * Warum: Das Plugin `RcCartSplitter` zerlegt eine Bestellung in mehrere
     *        Teil-Orders — pro Teil-Order feuert das Event. Beide Teil-Orders
     *        sollen denselben Snapshot der Pending-Uploads erhalten, sonst hat
     *        die zweite Teil-Order leere Anhänge.
     * Erwartet: Zwei `attach`-Aufrufe insgesamt (einer pro Order).
     */
    public function testReusesSnapshotAcrossMultipleOrdersForCartSplit(): void
    {
        $store = $this->newStore();
        $store->add(new PendingUpload('t1', 'media-1', 'a.pdf', 'application/pdf', 100, self::FIXED_UPLOADED_AT));

        $manager = $this->createMock(OrderAttachmentManagerInterface::class);
        // Erwartet: 1 attach beim ersten Order, 1 attach beim zweiten Order = 2 insgesamt
        $manager->expects(self::exactly(2))
            ->method('attach')
            ->willReturn(new OrderAttachmentEntity());

        $subscriber = new CheckoutOrderPlacedSubscriber($manager, $store, $this->permissiveBus(), new NullLogger());
        $subscriber->onOrderPlaced($this->event('order-1'));
        $subscriber->onOrderPlaced($this->event('order-2'));
    }

    /**
     * Was: `reset()` zwischen zwei Customer-Requests im Worker-Mode-Setup.
     * Warum: Bei Swoole/RoadRunner/FrankenPHP bleibt der Subscriber als
     *        Singleton zwischen Requests bestehen. Ohne `reset()` würde der
     *        Snapshot von Customer A für Customer B weiterleben — Cross-Customer-
     *        Datenleck (DSGVO + Datenintegrität).
     * Erwartet: Nach `reset()` und leerem Store löst Customer-B-Request keinen
     *           `attach`-Aufruf aus. Insgesamt nur ein `attach` für Customer A.
     */
    public function testResetClearsSnapshotForWorkerModeIsolation(): void
    {
        $store = $this->newStore();
        $store->add(new PendingUpload('t1', 'media-1', 'a.pdf', 'application/pdf', 100, self::FIXED_UPLOADED_AT));

        $manager = $this->createMock(OrderAttachmentManagerInterface::class);
        // Erster Request: 1 Attach für Customer A
        // Nach reset(): zweiter Request mit leerem Store → 0 Attaches für Customer B
        $manager->expects(self::once())
            ->method('attach')
            ->willReturn(new OrderAttachmentEntity());

        $subscriber = new CheckoutOrderPlacedSubscriber($manager, $store, $this->permissiveBus(), new NullLogger());
        $subscriber->onOrderPlaced($this->event('order-customer-a'));

        // Worker-Mode-Simulation: reset() läuft am Request-Ende
        $subscriber->reset();

        // Customer B startet seinen Order-Placement-Pfad mit leerem Store
        $store->clear();
        $subscriber->onOrderPlaced($this->event('order-customer-b'));
    }

    /**
     * Was: Zwei Pending-Uploads, der erste `attach`-Aufruf wirft eine Exception.
     * Warum: Ein einzelner Anhang-Schreibfehler darf den Order-Placement-Pfad
     *        nicht reißen — die Bestellung ist bereits fertig, eine harte
     *        Exception würde die Finish-Page kaputtmachen. Fail-Soft mit Log.
     * Erwartet: Beide `attach`-Aufrufe werden versucht (kein Early-Exit beim
     *           ersten Fehler), Exception wird geschluckt + geloggt.
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
     * Was: Ein Attach schlägt beim Order-Placement fehl.
     * Warum: Die bereits hochgeladenen Dokumente wären sonst still für die
     *        Bestellung verloren. Das Media existiert weiter — ein idempotenter
     *        Queue-Retry muss das Nachverknüpfen einplanen.
     * Erwartet: Genau eine `RetryOrderAttachmentLinkMessage` für die
     *           fehlgeschlagene mediaId wird dispatcht.
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
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message) use (&$dispatched): Envelope {
                $dispatched = $message;

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
