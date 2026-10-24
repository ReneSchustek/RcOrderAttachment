<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service;

use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Shopware\Core\Framework\Context;

/**
 * Speichert die Bindung zwischen Bestellung und Upload des Kunden. Die Schnittstelle lässt den
 * Bestell-Subscriber und den Wiederhol-Handler ohne Datenbank testen und erlaubt einen Dekorator,
 * ohne die Aufrufer anzufassen.
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
