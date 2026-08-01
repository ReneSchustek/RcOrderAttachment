<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\MessageHandler;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Message\RetryOrderAttachmentLinkMessage;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentManagerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Throwable;

/**
 * Verknüpft Customer-Uploads nach einem fehlgeschlagenen Order-Placement-Attach
 * erneut mit der Bestellung.
 *
 * Idempotent: bereits verknüpfte Media werden übersprungen (ein Retry darf nach
 * einem Teil-Erfolg keine Doppel-Zeilen erzeugen). Verbleibende Fehler werden mit
 * `attempt + 1` und Exponential-Backoff erneut eingeplant; ab {@see MAX_ATTEMPTS}
 * final geloggt, damit der Betrieb die Order manuell nacharbeiten kann.
 */
#[AsMessageHandler]
final class RetryOrderAttachmentLinkHandler
{
    public const MAX_ATTEMPTS = 5;

    private const BASE_DELAY_MS = 60_000;

    private const MAX_DELAY_MS = 3_600_000;

    public function __construct(
        private readonly OrderAttachmentManagerInterface $manager,
        private readonly OrderAttachmentLoader $loader,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RetryOrderAttachmentLinkMessage $message): void
    {
        $context = Context::createCLIContext();

        $alreadyLinked = [];
        foreach ($this->loader->loadForOrder($message->orderId, $context) as $attachment) {
            $alreadyLinked[$attachment->getMediaId()] = true;
        }

        $stillFailed = [];
        foreach ($message->uploads as $upload) {
            if (isset($alreadyLinked[$upload['mediaId']])) {
                continue;
            }

            try {
                $this->manager->attach(
                    orderId: $message->orderId,
                    mediaId: $upload['mediaId'],
                    originalFileName: $upload['originalFileName'],
                    mimeType: $upload['mimeType'],
                    fileSize: $upload['fileSize'],
                    context: $context,
                );
            } catch (Throwable $exception) {
                $stillFailed[] = $upload;
                $this->logger->error('rc_order_attachment.link_retry.attach_failed', [
                    'orderId' => $message->orderId,
                    'mediaId' => $upload['mediaId'],
                    'attempt' => $message->attempt,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($stillFailed === []) {
            $this->logger->info('rc_order_attachment.link_retry.resolved', [
                'orderId' => $message->orderId,
                'attempt' => $message->attempt,
            ]);

            return;
        }

        if ($message->attempt >= self::MAX_ATTEMPTS) {
            $this->logger->error('rc_order_attachment.link_retry.exhausted', [
                'orderId' => $message->orderId,
                'attempt' => $message->attempt,
                'failedCount' => \count($stillFailed),
            ]);

            return;
        }

        $this->messageBus->dispatch(
            new RetryOrderAttachmentLinkMessage($message->orderId, $stillFailed, $message->attempt + 1),
            [new DelayStamp($this->backoffMs($message->attempt))],
        );
    }

    private function backoffMs(int $attempt): int
    {
        $factor = 2 ** max(0, $attempt - 1);

        return min(self::MAX_DELAY_MS, self::BASE_DELAY_MS * $factor);
    }
}
