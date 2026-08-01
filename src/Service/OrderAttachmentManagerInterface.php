<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service;

use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Shopware\Core\Framework\Context;

/**
 * Persistiert die Bindung zwischen Bestellung und Customer-Upload. Eigene
 * Schnittstelle, damit Subscriber (OrderPlaced) und künftige Audit-Decorationen
 * entkoppelt testbar sind.
 */
interface OrderAttachmentManagerInterface
{
    public function attach(
        string $orderId,
        string $mediaId,
        string $originalFileName,
        string $mimeType,
        int $fileSize,
        Context $context,
    ): OrderAttachmentEntity;
}
