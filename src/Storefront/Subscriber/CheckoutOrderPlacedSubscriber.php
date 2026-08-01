<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Message\RetryOrderAttachmentLinkMessage;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentManagerInterface;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUpload;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUploadStore;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Framework\Context;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Verknüpft die Pending-Uploads aus der Session mit der/den gerade angelegten
 * Order(s).
 *
 * Multi-Order-Kompatibilität (RcCartSplitter):
 *   - `CheckoutOrderPlacedEvent` wird bei Cart-Split mehrfach gefeuert (eine
 *     Bestellung pro Teil-Order). Damit jede Teil-Order dieselben Anhänge
 *     erhält, wird die Pending-Liste beim ERSTEN Aufruf in eine Instance-Property
 *     gesnapshottet und bei jedem nachfolgenden Aufruf gegen die Snapshot-Liste
 *     verlinkt.
 *
 *   - Priority -500 stellt sicher, dass das Anhängen NACH dem Cart-Splitter
 *     läuft — so bestimmt es das Ruhrcoder-Plugin-Interaktionsprotokoll für Order-Subscriber.
 *
 *   - Geleert wird der PendingUploadStore erst auf `CheckoutFinishPageLoadedEvent`
 *     — also nach Ende des kompletten Order-Placement-Pfads.
 *
 * Worker-Mode-Sicherheit:
 *   Der Snapshot lebt als Instance-Property. Bei klassischem PHP-FPM wird der
 *   Service pro Request neu instanziiert — kein Leak. Bei Worker-Mode-Setups
 *   (Symfony-Runtime mit Swoole, RoadRunner, FrankenPHP) bleibt der Singleton
 *   zwischen Requests bestehen. Die Implementierung von {@see ResetInterface}
 *   sorgt dafür, dass der DI-Container am Ende jedes Requests `reset()` aufruft
 *   und der Snapshot garantiert pro Customer-Request neu beginnt — kein
 *   Cross-Customer-Datenleck.
 */
final class CheckoutOrderPlacedSubscriber implements EventSubscriberInterface, ResetInterface
{
    /**
     * @var array<string, PendingUpload>
     */
    private array $snapshot = [];

    private bool $snapshotTaken = false;

    public function __construct(
        private readonly OrderAttachmentManagerInterface $orderAttachmentManager,
        private readonly PendingUploadStore $pendingUploadStore,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function reset(): void
    {
        $this->snapshot = [];
        $this->snapshotTaken = false;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => ['onOrderPlaced', -500],
            CheckoutFinishPageLoadedEvent::class => ['onFinishPageLoaded', 0],
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $this->captureSnapshot();
        if ($this->snapshot === []) {
            return;
        }

        $orderId = $event->getOrder()->getId();
        $context = $event->getContext();
        $attached = 0;

        /** @var list<array{mediaId: string, originalFileName: string, mimeType: string, fileSize: int}> $failed */
        $failed = [];
        foreach ($this->snapshot as $upload) {
            if ($this->attachOne($upload, $orderId, $context)) {
                ++$attached;
            } else {
                $failed[] = [
                    'mediaId' => $upload->mediaId,
                    'originalFileName' => $upload->originalFileName,
                    'mimeType' => $upload->mimeType,
                    'fileSize' => $upload->fileSize,
                ];
            }
        }

        // Die Session-Liste JETZT leeren — im selben Request, in dem die Dateien den
        // Besitzer wechseln. Der Snapshot lebt im Arbeitsspeicher weiter und versorgt
        // weitere Teil-Orders desselben Requests (Cart-Split), das Leeren ist also
        // gefahrlos.
        //
        // Vorher geschah das erst in `onFinishPageLoaded()` — und dort nie: Order-Placement
        // (`POST /checkout/order`) und Finish-Page (`GET /checkout/finish`) sind zwei
        // getrennte Requests. Der `snapshotTaken`-Guard war im zweiten Request immer
        // `false`, weil der Service pro Request neu gebaut (PHP-FPM) bzw. per
        // `kernel.reset` zurückgesetzt wird (Worker-Mode). `clear()` war damit toter Code.
        //
        // Die Folgen waren gravierend: Die Datei blieb als „pending" in der Session, die
        // Confirm-Page des nächsten Warenkorbs zeigte sie samt gültigem Remove-Token.
        // Ein Klick auf „Entfernen" löschte das Media — und der Fremdschlüssel
        // `fk.rc_order_attachment.media_id` steht auf `ON DELETE CASCADE`, riss also den
        // Anhang der bereits abgeschlossenen Bestellung mit. Ohne Entfernen wurde
        // dieselbe Datei bei der nächsten Bestellung erneut angehängt und verschickt.
        $this->pendingUploadStore->clear();

        $this->logger->info('rc_order_attachment.order_placed', [
            'orderId' => $orderId,
            'snapshot' => \count($this->snapshot),
            'attached' => $attached,
        ]);

        // Konnten Uploads nicht verknüpft werden (z. B. transienter DB-Fehler),
        // wären sie sonst still für die Bestellung verloren. Das Media existiert
        // bereits — ein idempotenter Retry über die Queue holt das Verknüpfen nach.
        if ($failed !== []) {
            $this->messageBus->dispatch(new RetryOrderAttachmentLinkMessage($orderId, $failed));
            $this->logger->warning('rc_order_attachment.order_placed.link_retry_scheduled', [
                'orderId' => $orderId,
                'failedCount' => \count($failed),
            ]);
        }
    }

    public function onFinishPageLoaded(CheckoutFinishPageLoadedEvent $event): void
    {
        // Sicherheitsnetz für Pfade, die die Finish-Page ohne vorheriges
        // `checkout.order.placed` erreichen (z. B. Rückkehr nach externem
        // Zahlungsanbieter). `clear()` ist idempotent.
        $this->pendingUploadStore->clear();
        $this->snapshot = [];
        $this->snapshotTaken = false;

        $this->logger->debug('rc_order_attachment.finish_page.cleared', [
            'orderId' => $event->getPage()->getOrder()->getId(),
        ]);
    }

    private function captureSnapshot(): void
    {
        if ($this->snapshotTaken) {
            return;
        }
        $this->snapshot = $this->pendingUploadStore->all();
        $this->snapshotTaken = true;
    }

    private function attachOne(PendingUpload $upload, string $orderId, Context $context): bool
    {
        try {
            $this->orderAttachmentManager->attach(
                orderId: $orderId,
                mediaId: $upload->mediaId,
                originalFileName: $upload->originalFileName,
                mimeType: $upload->mimeType,
                fileSize: $upload->fileSize,
                context: $context,
            );

            return true;
        } catch (Throwable $exception) {
            $this->logger->error('rc_order_attachment.order_placed.attach_failed', [
                'orderId' => $orderId,
                'mediaId' => $upload->mediaId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
