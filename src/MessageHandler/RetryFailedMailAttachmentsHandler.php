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
 * Opt-in-Retry für fehlgeschlagene Mail-Anhänge.
 *
 * Strategie: prüft, ob die Anhänge inzwischen lesbar sind. Lesbar → Status auf
 * `attached`. Nicht lesbar → eine neue Nachricht mit `attempt + 1` und
 * {@see DelayStamp} (Exponential-Backoff) dispatchen. Ab {@see MAX_ATTEMPTS}
 * final `failed`.
 *
 * Warum Re-Dispatch statt `RecoverableMessageHandlingException`:
 *   Symfony-Messenger stellt bei einer Recoverable-Exception dieselbe Nachricht
 *   erneut zu — das `attempt`-Feld bleibt dabei unverändert bei 1. Die
 *   Abbruchbedingung `attempt >= MAX_ATTEMPTS` war damit unerreichbar, der
 *   finale `failed`-Status wurde nie geschrieben, und die Zahl der Versuche
 *   hing allein an der `retry_strategy` des Transports. Mit explizitem
 *   Re-Dispatch zählt der Handler selbst und ist unabhängig von der
 *   Transport-Konfiguration des Betreibers.
 *
 * Der eigentliche Mail-Re-Send bleibt bewusst Operator-Aufgabe — ein blinder
 * Re-Send würde den Customer mit Duplikaten konfrontieren.
 */
#[AsMessageHandler]
final class RetryFailedMailAttachmentsHandler
{
    public const MAX_ATTEMPTS = 5;

    /**
     * Basis des Exponential-Backoff: Versuch n wartet `BASE * 2^(n-1)`,
     * gedeckelt auf {@see MAX_DELAY_MS}.
     */
    private const BASE_DELAY_MS = 60_000;

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
            // Treue der Telemetrie erhalten: Wenn nur ein Teil der Anhänge
            // dauerhaft unlesbar bleibt (0 < failures < total), ist das ein
            // `partial_failure` — pauschales `failed` würde suggerieren, dass
            // gar kein Anhang ankam, obwohl welche zugestellt wurden.
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
