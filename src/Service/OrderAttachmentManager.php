<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Service\Exception\AttachmentNotFoundException;
use Ruhrcoder\RcOrderAttachment\Service\Exception\InvalidAttachmentPayloadException;
use Ruhrcoder\RcOrderAttachment\Service\Filename\FilenameSanitizer;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Persistiert die finale Bindung zwischen einer Bestellung und den vom Kunden
 * im Checkout hochgeladenen Dateien. Wird nach erfolgreichem `checkout.order.placed`
 * aufgerufen.
 */
final class OrderAttachmentManager implements OrderAttachmentManagerInterface
{
    /**
     * @param EntityRepository<OrderAttachmentCollection> $attachmentRepository
     * @param EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly EntityRepository $attachmentRepository,
        private readonly EntityRepository $orderRepository,
        private readonly OrderAttachmentLoader $loader,
        private readonly FilenameSanitizer $filenameSanitizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function attach(
        string $orderId,
        string $mediaId,
        string $originalFileName,
        string $mimeType,
        int $fileSize,
        Context $context,
    ): OrderAttachmentEntity {
        $this->assertUuid($orderId, 'orderId');
        $this->assertUuid($mediaId, 'mediaId');

        $normalizedFileName = $this->filenameSanitizer->sanitize($originalFileName);

        if ($fileSize < 0) {
            throw new InvalidAttachmentPayloadException('fileSize', 'darf nicht negativ sein');
        }

        $orderVersionId = $this->resolveOrderVersionId($orderId, $context);

        $attachmentId = Uuid::randomHex();

        $this->attachmentRepository->create([[
            'id' => $attachmentId,
            'orderId' => $orderId,
            'orderVersionId' => $orderVersionId,
            'mediaId' => $mediaId,
            'originalFileName' => $normalizedFileName,
            'mimeType' => $this->normalizeMimeType($mimeType),
            'fileSize' => $fileSize,
        ]], $context);

        $this->logger->info('rc_order_attachment.attach', [
            'attachmentId' => $attachmentId,
            'orderId' => $orderId,
            'mediaId' => $mediaId,
            'fileSize' => $fileSize,
        ]);

        return $this->loader->loadById($attachmentId, $context);
    }

    private function assertUuid(string $value, string $field): void
    {
        if (!Uuid::isValid($value)) {
            throw new InvalidAttachmentPayloadException($field, 'keine gültige UUID');
        }
    }

    private function normalizeMimeType(string $mimeType): string
    {
        $trimmed = trim($mimeType);
        if ($trimmed === '') {
            return 'application/octet-stream';
        }

        return mb_substr($trimmed, 0, 127);
    }

    private function resolveOrderVersionId(string $orderId, Context $context): string
    {
        $criteria = new Criteria([$orderId]);
        $criteria->setLimit(1);

        $order = $this->orderRepository->search($criteria, $context)->first();
        if (!$order instanceof OrderEntity) {
            throw new AttachmentNotFoundException($orderId);
        }

        return $order->getVersionId() ?? Defaults::LIVE_VERSION;
    }
}
