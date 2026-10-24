<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Legt die Tabelle an, die eine Bestellung mit den vom Kunden im Checkout hochgeladenen
 * Dokumenten verbindet (Zeichnungen, Skizzen, Pläne).
 *
 * Die Migration ist veröffentlicht und wird nicht mehr verändert; Änderungen am Schema brauchen
 * eine neue Migration. Beide Fremdschlüssel löschen kaskadierend: Verschwindet die Bestellung oder
 * das Medium, verschwindet auch die Zeile, und es bleibt kein Anhang ohne Datei zurück.
 */
final class Migration1715200000CreateOrderAttachmentTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1715200000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `rc_order_attachment` (
                `id` BINARY(16) NOT NULL,
                `order_id` BINARY(16) NOT NULL,
                `order_version_id` BINARY(16) NOT NULL,
                `media_id` BINARY(16) NOT NULL,
                `original_file_name` VARCHAR(255) NOT NULL,
                `mime_type` VARCHAR(127) NOT NULL,
                `file_size` INT UNSIGNED NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                KEY `idx.rc_order_attachment.order_id` (`order_id`, `order_version_id`),
                KEY `idx.rc_order_attachment.media_id` (`media_id`),
                KEY `idx.rc_order_attachment.created_at` (`created_at`),
                CONSTRAINT `fk.rc_order_attachment.order_id` FOREIGN KEY (`order_id`, `order_version_id`)
                    REFERENCES `order` (`id`, `version_id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.rc_order_attachment.media_id` FOREIGN KEY (`media_id`)
                    REFERENCES `media` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Die Tabelle entfernt allein `uninstall()` mit `keepUserData = false`.
    }
}
