<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Über die Warteschlange, damit ein Fehler beim Versand die Bestellung nie aufhält.
 */
final readonly class SendShopAttachmentMailMessage implements AsyncMessageInterface
{
    public function __construct(public string $orderId)
    {
    }
}
