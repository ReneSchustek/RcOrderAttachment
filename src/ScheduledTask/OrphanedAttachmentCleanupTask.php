<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Löscht private Medien im Plugin-Ordner, die über keine `rc_order_attachment`-Zeile an eine
 * Bestellung gebunden sind.
 *
 * Ohne diese Aufgabe blieben Uploads von Kunden, die den Checkout abbrechen oder deren Sitzung
 * abläuft, dauerhaft auf dem Server liegen.
 */
final class OrphanedAttachmentCleanupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_order_attachment.cleanup_orphaned';
    }

    public static function getDefaultInterval(): int
    {
        // Stündlich, weil die Frist für verwaiste Uploads in Stunden zählt.
        return 3600;
    }
}
