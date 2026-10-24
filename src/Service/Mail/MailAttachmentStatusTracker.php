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
 * Schreibt das interne Zusatzfeld `rc_order_attachment_mail_status` an die Bestellung. Der
 * Mail-Subscriber und der Wiederhol-Handler müssen dadurch nichts über das Speichern wissen.
 *
 * Denselben Status erneut zu setzen hat keine weitere Wirkung.
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
            // Der Status ist nur ein Hinweis für den Betrieb; scheitert das Schreiben, soll die
            // Mail trotzdem hinausgehen.
            $this->logger->warning('rc_order_attachment.mail.status_write_failed', [
                'orderId' => $orderId,
                'status' => $status,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
