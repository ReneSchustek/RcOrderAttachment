<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Message\RetryOrderAttachmentLinkMessage;
use Ruhrcoder\RcOrderAttachment\Message\SendShopAttachmentMailMessage;
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
 * Verknüpft die vorgemerkten Uploads aus der Sitzung mit der gerade angelegten Bestellung oder,
 * bei einem geteilten Warenkorb, mit jeder Teilbestellung.
 *
 * Teilt ein Plugin wie RcCartSplitter den Warenkorb, löst Shopware `CheckoutOrderPlacedEvent`
 * einmal je Teilbestellung aus. Damit jede dieselben Anhänge bekommt, hält der Subscriber die
 * Liste beim ersten Aufruf in einer Eigenschaft fest und verknüpft bei jedem weiteren Aufruf
 * derselben Anfrage gegen diese Kopie. Die Priorität -500 lässt ihn nach den Subscribern laufen,
 * die den Warenkorb aufteilen.
 *
 * Die Kopie lebt in der Instanz. Unter PHP-FPM entsteht der Dienst je Anfrage neu; in einem
 * Worker-Betrieb (Swoole, RoadRunner, FrankenPHP) bleibt er zwischen Anfragen bestehen. Über
 * {@see ResetInterface} ruft der Container am Ende jeder Anfrage `reset()` auf, sodass die Kopie
 * nie in die Anfrage eines anderen Kunden hinüberreicht.
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
            // -500: nach den Subscribern, die den Warenkorb in Teilbestellungen aufteilen.
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

        // Die Liste in der Sitzung wird in derselben Anfrage geleert, in der die Dateien zur
        // Bestellung wandern. Weitere Teilbestellungen dieser Anfrage versorgt die Kopie im
        // Arbeitsspeicher.
        //
        // Später wäre zu spät: Bestellabschluss (`POST /checkout/order`) und Abschlussseite
        // (`GET /checkout/finish`) sind zwei Anfragen, und die Abschlussseite wird nicht immer
        // erreicht. Bliebe die Datei vorgemerkt, zeigte die Bestätigungsseite des nächsten
        // Warenkorbs sie samt gültigem Token zum Entfernen. Ein Klick darauf löschte das Medium,
        // und der kaskadierende Fremdschlüssel `fk.rc_order_attachment.media_id` nähme den
        // Anhang der abgeschlossenen Bestellung mit. Ohne Klick hinge dieselbe Datei an der
        // nächsten Bestellung.
        $this->pendingUploadStore->clear();

        $this->logger->info('rc_order_attachment.order_placed', [
            'orderId' => $orderId,
            'snapshot' => \count($this->snapshot),
            'attached' => $attached,
        ]);

        if ($attached > 0) {
            $this->messageBus->dispatch(new SendShopAttachmentMailMessage($orderId));
        }

        // Scheitert das Verknüpfen, etwa an einem vorübergehenden Datenbankfehler, gingen die
        // Dateien für die Bestellung still verloren. Die Medien existieren schon, also holt ein
        // Wiederholversuch über die Warteschlange das Verknüpfen nach.
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
        // Rückfall für Wege, die die Abschlussseite ohne vorheriges `checkout.order.placed` in
        // dieser Sitzung erreichen, etwa nach der Rückkehr von einem externen Zahlungsanbieter.
        // Ein zweites `clear()` schadet nicht.
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
