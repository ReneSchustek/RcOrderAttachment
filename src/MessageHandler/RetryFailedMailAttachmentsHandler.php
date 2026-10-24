<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\MessageHandler;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Message\RetryFailedMailAttachmentsMessage;
use Ruhrcoder\RcOrderAttachment\Service\Mail\MailAttachmentStatusTrackerInterface;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

/**
 * Prüft nach einem gescheiterten Mailanhang, ob die Dateien inzwischen lesbar sind, und trägt den
 * Anhangstatus der Bestellung nach. Die Nachricht entsteht nur, wenn der Betreiber die
 * Wiederholung in der Konfiguration eingeschaltet hat.
 *
 * Sind alle Anhänge lesbar, wird der Status `attached`. Sonst plant der Handler eine neue Nachricht
 * mit `attempt + 1` und wachsender Wartezeit ein; nach {@see MAX_ATTEMPTS} Versuchen schreibt er
 * `failed` oder `partial_failure`.
 *
 * Eine `RecoverableMessageHandlingException` wäre die naheliegende Alternative, taugt aber nicht:
 * Symfony stellt dann dieselbe Nachricht erneut zu, `attempt` bliebe bei 1, die Abbruchbedingung
 * wäre unerreichbar und die Zahl der Versuche hinge allein an der `retry_strategy` des Transports.
 * Mit einer eigenen neuen Nachricht zählt der Handler selbst.
 *
 * Die Mail erneut zu verschicken bleibt Aufgabe des Betriebs, weil ein blinder zweiter Versand
 * dem Kunden doppelte Bestätigungen schickt.
 */
#[AsMessageHandler]
final class RetryFailedMailAttachmentsHandler
{
    /**
     * Fünf Versuche mit 1, 2, 4 und 8 Minuten Abstand ergeben rund eine Viertelstunde, in der sich
     * ein kurz gestörter Speicher erholen kann. Mehr Versuche verzögern nur die endgültige Meldung
     * an den Betrieb, wenn die Datei wirklich fehlt.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Versuch n wartet `BASE_DELAY_MS * 2^(n-1)`, gedeckelt auf {@see MAX_DELAY_MS}. Eine Minute
     * gibt dem Speicher Zeit für einen Neustart, ohne den Status lange offen zu lassen.
     */
    private const BASE_DELAY_MS = 60_000;

    // Die Obergrenze von einer Stunde greift erst, wenn MAX_ATTEMPTS über sieben steigt.
    private const MAX_DELAY_MS = 3_600_000;

    public function __construct(
        private readonly OrderAttachmentLoader $loader,
        private readonly MediaService $mediaService,
        private readonly MailAttachmentStatusTrackerInterface $statusTracker,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RetryFailedMailAttachmentsMessage $message): void
    {
        $context = Context::createCLIContext();
        $attachments = $this->loader->loadForOrder($message->orderId, $context);

        if ($attachments->count() === 0) {
            $this->statusTracker->markFailed($message->orderId, $context);
            $this->logger->info('rc_order_attachment.mail.retry_no_attachments', [
                'orderId' => $message->orderId,
                'attempt' => $message->attempt,
            ]);

            return;
        }

        $failures = $this->countUnreadable($attachments->getElements(), $context);

        if ($failures === 0) {
            $this->statusTracker->markAttached($message->orderId, $context);
            $this->logger->info('rc_order_attachment.mail.retry_resolved', [
                'orderId' => $message->orderId,
                'attempt' => $message->attempt,
                'attachmentCount' => $attachments->count(),
            ]);

            return;
        }

        if ($message->attempt >= self::MAX_ATTEMPTS) {
            // Bleibt nur ein Teil der Anhänge unlesbar, ist das `partial_failure`. Ein pauschales
            // `failed` behauptete, es sei gar kein Anhang angekommen.
            if ($failures < $attachments->count()) {
                $this->statusTracker->markPartialFailure($message->orderId, $context);
            } else {
                $this->statusTracker->markFailed($message->orderId, $context);
            }

            $this->logger->warning('rc_order_attachment.mail.retry_exhausted', [
                'orderId' => $message->orderId,
                'attempt' => $message->attempt,
                'failureCount' => $failures,
                'attachmentCount' => $attachments->count(),
            ]);

            return;
        }

        $this->scheduleNextAttempt($message, $failures);
    }

    /**
     * @param array<string, OrderAttachmentEntity> $attachments
     */
    private function countUnreadable(array $attachments, Context $context): int
    {
        $failures = 0;

        foreach ($attachments as $attachment) {
            $media = $attachment->getMedia();
            if (!$media instanceof MediaEntity) {
                ++$failures;

                continue;
            }

            // Das Medium ist privat; lesen darf es nur der Systemkontext.
            try {
                $context->scope(
                    Context::SYSTEM_SCOPE,
                    fn (Context $systemContext): string => $this->mediaService->loadFile($media->getId(), $systemContext),
                );
            } catch (Throwable) {
                ++$failures;
            }
        }

        return $failures;
    }

    private function scheduleNextAttempt(RetryFailedMailAttachmentsMessage $message, int $failures): void
    {
        $nextAttempt = $message->attempt + 1;
        $delayMs = $this->backoffMs($message->attempt);

        $this->messageBus->dispatch(
            new RetryFailedMailAttachmentsMessage($message->orderId, $nextAttempt),
            [new DelayStamp($delayMs)],
        );

        $this->logger->info('rc_order_attachment.mail.retry_rescheduled', [
            'orderId' => $message->orderId,
            'attempt' => $message->attempt,
            'nextAttempt' => $nextAttempt,
            'delayMs' => $delayMs,
            'failureCount' => $failures,
        ]);
    }

    private function backoffMs(int $attempt): int
    {
        $factor = 2 ** max(0, $attempt - 1);

        return min(self::MAX_DELAY_MS, self::BASE_DELAY_MS * $factor);
    }
}
