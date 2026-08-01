<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Mail;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Installer\CustomFieldInstaller;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Throwable;

/**
 * Schreibt das interne Custom-Field `rc_order_attachment_mail_status` auf
 * `order`. Reine DAL-Operation — der MailBeforeValidate-Subscriber bleibt frei
 * von Persistenz-Logik.
 *
 * Idempotent: derselbe Status wird ohne weitere Seiteneffekte erneut gesetzt.
 */
final class MailAttachmentStatusTracker implements MailAttachmentStatusTrackerInterface
{
    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function markAttached(string $orderId, Context $context): void
    {
        $this->mark($orderId, CustomFieldInstaller::STATUS_ATTACHED, $context);
    }

    public function markPartialFailure(string $orderId, Context $context): void
    {
        $this->mark($orderId, CustomFieldInstaller::STATUS_PARTIAL_FAILURE, $context);
    }

    public function markFailed(string $orderId, Context $context): void
    {
        $this->mark($orderId, CustomFieldInstaller::STATUS_FAILED, $context);
    }

    private function mark(string $orderId, string $status, Context $context): void
    {
        if (!Uuid::isValid($orderId)) {
            return;
        }

        try {
            $this->orderRepository->update([[
                'id' => $orderId,
                'customFields' => [
                    CustomFieldInstaller::FIELD_MAIL_STATUS => $status,
                ],
            ]], $context);
        } catch (Throwable $exception) {
            // Status-Schreiben darf den Mail-Versand nicht blockieren —
            // Telemetrie bleibt ein Best-Effort-Signal.
            $this->logger->warning('rc_order_attachment.mail.status_write_failed', [
                'orderId' => $orderId,
                'status' => $status,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
