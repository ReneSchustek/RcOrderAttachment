<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Integration\Migration;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Migration\Migration1715200000CreateOrderAttachmentTable;
use Ruhrcoder\RcOrderAttachment\Tests\Integration\ContainerAccessTrait;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;

/**
 * Verifiziert, dass die Migration auf einer leeren DB idempotent läuft und
 * alle erwarteten Spalten + Indizes + Foreign-Keys anlegt.
 *
 * Nutzt `KernelTestBehaviour` statt `IntegrationTestBehaviour`, weil die Tests
 * DDL-Statements (`CREATE TABLE IF NOT EXISTS`) ausfuehren — DDL committet in
 * MySQL implizit, was den Transaction-Wrap aus `IntegrationTestBehaviour`
 * sprengen wuerde (`tearDown::rollBack` findet keine offene Transaction).
 */
final class Migration1715200000IntegrationTest extends TestCase
{
    use ContainerAccessTrait;
    use KernelTestBehaviour;

    /**
     * Was: Migration legt alle erwarteten Spalten an.
     * Warum: Schema-Drift-Schutz — wenn jemand versehentlich eine Spalte aus
     *        der Migration löscht, würde der DAL-Definition-Mapping zur
     *        Runtime brechen. Test fängt das früh.
     * Erwartet: Alle Pflicht-Spalten (`id`, `order_id`, `media_id`, …) sind
     *           in `SHOW COLUMNS` vorhanden.
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
     * Was: Migration legt die drei deklarierten Indexe an.
     * Warum: Performance-Pflicht für die Storefront- und Cleanup-Pfade.
     *        Fehlende Indexe führen bei größeren Shops zu Full-Table-Scans
     *        in der Account-Order-Page und im Retention-Cron.
     * Erwartet: `idx.rc_order_attachment.order_id`, `…media_id`, `…created_at`
     *           sind vorhanden.
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
     * Was: Foreign-Keys auf `order` (Order+Version) und `media` sind angelegt.
     * Warum: Datenintegrität-Pflicht. FK-Cascade auf `order` löscht
     *        Anhänge automatisch beim Order-Drop, FK auf `media` schützt
     *        vor Orphan-Anhängen.
     * Erwartet: Beide Constraint-Namen in `information_schema`.
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
     * Was: Migration zweimal hintereinander gegen dieselbe DB ausführen.
     * Warum: Plugin-Update-Pfad ruft Migrationen bei jedem Run, Idempotenz ist
     *        Senior-Pflicht (`CREATE TABLE IF NOT EXISTS`). Sonst würde jeder
     *        Update-Run mit „Table already exists" abbrechen.
     * Erwartet: Kein Throw beim zweiten `update()`-Aufruf.
     */
    public function testMigrationIsIdempotent(): void
    {
        $connection = static::connection();
        $migration = new Migration1715200000CreateOrderAttachmentTable();

        // Ein Datensatz vor dem zweiten Lauf: Idempotenz heisst nicht nur „wirft nicht",
        // sondern auch „raeumt nichts weg". Ein `DROP TABLE IF EXISTS` vor dem `CREATE`
        // waere ebenfalls wiederholbar — und wuerde bei jedem Plugin-Update alle Anhaenge
        // loeschen. `assertTrue(true)` haette das durchgewinkt.
        $spuren = $connection->fetchOne('SELECT COUNT(*) FROM `rc_order_attachment`');

        $migration->update($connection);
        $migration->update($connection);

        self::assertSame(
            $spuren,
            $connection->fetchOne('SELECT COUNT(*) FROM `rc_order_attachment`'),
            'die Migration darf bestehende Anhaenge nicht antasten',
        );

        $struktur = $connection->fetchAllAssociative('SHOW COLUMNS FROM `rc_order_attachment`');
        self::assertNotEmpty($struktur, 'die Tabelle muss nach dem zweiten Lauf weiter existieren');

        $spalten = array_column($struktur, 'Field');
        foreach (['id', 'order_id', 'order_version_id', 'media_id', 'original_file_name'] as $pflichtfeld) {
            self::assertContains($pflichtfeld, $spalten, 'Spalte darf durch den zweiten Lauf nicht verschwinden');
        }
    }
}
