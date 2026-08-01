<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Löscht Customer-Uploads abgeschlossener Bestellungen nach Ablauf der
 * konfigurierten DSGVO-Retention (Default 180 Tage, 0 deaktiviert).
 */
final class ExpiredAttachmentCleanupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_order_attachment.cleanup_expired';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // täglich
    }
}
