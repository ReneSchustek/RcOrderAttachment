<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Löscht die Anhänge von Bestellungen, sobald die eingestellte Aufbewahrungsfrist abgelaufen ist.
 * Maßgeblich ist das Alter des Anhangs, nicht der Status der Bestellung; die Frist 0 schaltet das
 * Löschen ab.
 */
final class ExpiredAttachmentCleanupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_order_attachment.cleanup_expired';
    }

    public static function getDefaultInterval(): int
    {
        // Täglich, weil die Frist in Tagen zählt; ein häufigerer Lauf fände kaum Neues.
        return 86400;
    }
}
