<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Integration\Migration;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Migration\Migration1715200000CreateOrderAttachmentTable;
use Ruhrcoder\RcOrderAttachment\Tests\Integration\ContainerAccessTrait;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * Prüft das Schema der Anhangstabelle (Spalten, Indizes, Fremdschlüssel) und dass ein
 * wiederholter Migrationslauf nichts zerstört, denn jedes Plugin-Update ruft die Migration erneut.
 *
 * Die Klasse nutzt `KernelTestBehaviour` statt `IntegrationTestBehaviour`, weil DDL in MySQL
 * implizit committet. Die Transaktion um jeden Test wäre danach schon geschlossen, und
 * `rollBack()` im `tearDown` fände keine offene Transaktion mehr.
 */
final class Migration1715200000IntegrationTest extends TestCase
{
    use ContainerAccessTrait;
    use KernelTestBehaviour;

    /**
     * Fehlt eine Spalte, die die Entity-Definition erwartet, bricht das DAL-Mapping erst zur
     * Laufzeit. Der Test meldet eine solche Abweichung schon beim Lauf der Suite.
     */
    public function testTableCreatedWithCorrectStructure(): void
    {
        $connection = static::connection();

        $columns = $connection->fetchAllAssociative('SHOW COLUMNS FROM `rc_order_attachment`');
        $columnNames = array_column($columns, 'Field');

        $expected = [
            'id', 'order_id', 'order_version_id', 'media_id',
            'original_file_name', 'mime_type', 'file_size',
            'created_at', 'updated_at',
        ];

        foreach ($expected as $field) {
            self::assertContains($field, $columnNames, "Spalte $field fehlt");
        }
    }

    /**
     * Ohne diese drei Indizes liest die Bestellseite im Kundenkonto und die
     * Aufbewahrungsbereinigung in größeren Shops die ganze Tabelle.
     */
    public function testIndexesPresent(): void
    {
        $connection = static::connection();
        $indexes = $connection->fetchAllAssociative('SHOW INDEX FROM `rc_order_attachment`');
        $indexNames = array_unique(array_column($indexes, 'Key_name'));

        self::assertContains('idx.rc_order_attachment.order_id', $indexNames);
        self::assertContains('idx.rc_order_attachment.media_id', $indexNames);
        self::assertContains('idx.rc_order_attachment.created_at', $indexNames);
    }

    /**
     * Beide Fremdschlüssel löschen per Kaskade mit: Verschwindet die Bestellung oder das
     * Medium, verschwindet auch die Anhangszeile, statt ins Leere zu zeigen.
     */
    public function testForeignKeysPresent(): void
    {
        $connection = static::connection();
        $fks = $connection->fetchAllAssociative(
            'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_NAME = "rc_order_attachment" AND CONSTRAINT_TYPE = "FOREIGN KEY"'
        );
        $fkNames = array_column($fks, 'CONSTRAINT_NAME');

        self::assertContains('fk.rc_order_attachment.order_id', $fkNames);
        self::assertContains('fk.rc_order_attachment.media_id', $fkNames);
    }

    /**
     * Jedes Plugin-Update ruft die Migration erneut auf. Ohne `CREATE TABLE IF NOT EXISTS`
     * bräche es mit „Table already exists" ab.
     */
    public function testMigrationIsIdempotent(): void
    {
        $connection = static::connection();
        $migration = new Migration1715200000CreateOrderAttachmentTable();

        // Die Zeilenzahl vor und nach den Läufen wird verglichen, weil Wiederholbarkeit
        // auch heißt, dass nichts weggeräumt wird. Ein `DROP TABLE IF EXISTS` vor dem
        // `CREATE` liefe ebenfalls fehlerfrei, löschte aber bei jedem Update alle Anhänge.
        $traces = $connection->fetchOne('SELECT COUNT(*) FROM `rc_order_attachment`');

        $migration->update($connection);
        $migration->update($connection);

        self::assertSame(
            $traces,
            $connection->fetchOne('SELECT COUNT(*) FROM `rc_order_attachment`'),
            'die Migration darf bestehende Anhänge nicht antasten',
        );

        $structure = $connection->fetchAllAssociative('SHOW COLUMNS FROM `rc_order_attachment`');
        self::assertNotEmpty($structure, 'die Tabelle muss nach dem zweiten Lauf weiter existieren');

        $tableColumns = array_column($structure, 'Field');
        foreach (['id', 'order_id', 'order_version_id', 'media_id', 'original_file_name'] as $requiredField) {
            self::assertContains($requiredField, $tableColumns, 'Spalte darf durch den zweiten Lauf nicht verschwinden');
        }
    }
}
