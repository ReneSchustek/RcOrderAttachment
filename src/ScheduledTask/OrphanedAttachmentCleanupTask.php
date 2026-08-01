<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Räumt private Media-Records des Plugin-Folders auf, die nicht über ein
 * `rc_order_attachment`-Datensatz an eine Order gebunden sind.
 *
 * Schutz davor, dass Customer-Uploads ohne anschließenden Order-Abschluss
 * (Session-Timeout, Cart-Abbruch) dauerhaft auf dem Server bleiben.
 */
final class OrphanedAttachmentCleanupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_order_attachment.cleanup_orphaned';
    }

    public static function getDefaultInterval(): int
    {
        return 3600; // stündlich
    }
}
