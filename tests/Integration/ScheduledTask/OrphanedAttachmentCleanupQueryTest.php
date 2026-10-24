<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Integration\ScheduledTask;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ruhrcoder\RcOrderAttachment\Installer\MediaFolderInstaller;
use Ruhrcoder\RcOrderAttachment\ScheduledTask\OrphanedAttachmentCleanupTaskHandler;
use Ruhrcoder\RcOrderAttachment\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft die Auswahlabfrage der Waisenbereinigung an einer echten Datenbank. Der Handler löscht
 * private Kundenuploads, und welche Zeilen seine Abfrage liefert, kann ein Unit-Test mit Mock
 * nicht zeigen, weil der Mock zurückgibt, was der Test vorgibt.
 *
 * Jede der drei Bedingungen schützt fremdes Eigentum: `media_folder_id` die übrigen Medien des
 * Shops, `created_at < cutoff` laufende Uploads und `a.id IS NULL` aus dem LEFT JOIN die an
 * Bestellungen gebundenen Dateien. Fällt eine weg, löscht der Handler Dateien, die jemandem gehören.
 */
final class OrphanedAttachmentCleanupQueryTest extends IntegrationTestCase
{
    // Vorgabe aus config.xml; die Grenzfälle mit 23 und 25 Stunden liegen beiderseits.
    private const RETENTION_HOURS = 24;

    private Connection $connection;

    /** @var EntityRepository<\Shopware\Core\Content\Media\MediaCollection> */
    private EntityRepository $mediaRepository;

    private OrphanedAttachmentCleanupTaskHandler $handler;

    private string $pluginFolderId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = static::connection();
        $mediaRepository = static::repository('media.repository');
        /** @var EntityRepository<MediaCollection> $mediaRepository */
        $this->mediaRepository = $mediaRepository;
        $this->handler = static::service(OrphanedAttachmentCleanupTaskHandler::class);

        static::systemConfig()
            ->set('RcOrderAttachment.config.orphanRetentionHours', self::RETENTION_HOURS);

        $installer = static::service(MediaFolderInstaller::class);
        $folderId = $installer->findFolderId($this->context) ?? $installer->ensureFolder($this->context);
        $this->pluginFolderId = $folderId;
    }

    /**
     * Der Fall, für den der Handler da ist: ein Upload, aus dem nie eine Bestellung wurde.
     */
    public function testDeletesOrphanOlderThanRetention(): void
    {
        $orphaned = $this->media($this->pluginFolderId, '-25 hours');

        $this->handler->run();

        self::assertFalse($this->exists($orphaned), 'ein echter Waise muss verschwinden');
    }

    /**
     * Der wichtigste Fall der Klasse: Fiele der LEFT JOIN weg, löschte der Handler die Anhänge
     * abgeschlossener Bestellungen, und der Betrieb stünde ohne die hochgeladene Datei da.
     */
    public function testKeepsMediaReferencedByAnAttachment(): void
    {
        $bound = $this->media($this->pluginFolderId, '-25 hours');
        $this->createAttachment($bound, '-25 hours');

        $this->handler->run();

        self::assertTrue(
            $this->exists($bound),
            'an eine Bestellung gebundene Dateien dürfen der Waisen-Suche nie ins Netz gehen',
        );
    }

    /**
     * Zwischen dem Upload auf der Bestätigungsseite und dem Absenden der Bestellung liegt Zeit.
     * Griffe der Handler zu früh, verschwände die Datei aus dem laufenden Bestellvorgang.
     */
    public function testKeepsMediaYoungerThanRetention(): void
    {
        $fresh = $this->media($this->pluginFolderId, '-1 hour');

        $this->handler->run();

        self::assertTrue($this->exists($fresh), 'ein laufender Upload darf nicht abgeräumt werden');
    }

    /**
     * Ein Fehler um eine Stunde ist in der Abfrage unsichtbar und im Betrieb teuer. Erst eine
     * Datei auf jeder Seite der Frist legt fest, wo geschnitten wird.
     */
    public function testCutoffCutsOnTheRightSide(): void
    {
        $justAbove = $this->media($this->pluginFolderId, '-25 hours');
        $justBelow = $this->media($this->pluginFolderId, '-23 hours');

        $this->handler->run();

        self::assertFalse($this->exists($justAbove), 'älter als 24 h muss weg');
        self::assertTrue($this->exists($justBelow), 'jünger als 24 h muss bleiben');
    }

    /**
     * Der Ordnerfilter trennt die Uploads des Plugins vom übrigen Medienbestand des Shops. Ohne
     * ihn räumte der Handler jedes alte, unverknüpfte Medium ab, auch Produktbilder und Dokumente.
     */
    public function testNeverTouchesMediaOutsideThePluginFolder(): void
    {
        $foreign = $this->media(null, '-25 hours');

        $this->handler->run();

        self::assertTrue($this->exists($foreign), 'ausserhalb des Plugin-Ordners hat der Handler nichts zu suchen');
    }

    /**
     * Anders als bei der Anhangsaufbewahrung bedeutet 0 hier nicht „aus": `PluginConfigProvider`
     * hebt die Waisenfrist mit `max(1, …)` auf eine Stunde an. Ohne diese Untergrenze löschte der
     * nächste Lauf eine Datei, die der Kunde gerade hochgeladen hat.
     */
    public function testRetentionIsClampedToAtLeastOneHour(): void
    {
        static::systemConfig()
            ->set('RcOrderAttachment.config.orphanRetentionHours', 0);

        $old = $this->media($this->pluginFolderId, '-100 hours');
        $justNow = $this->media($this->pluginFolderId, '-0 hours');

        $this->handler->run();

        self::assertFalse($this->exists($old), 'alte Waisen werden auch bei 0 abgeräumt');
        self::assertTrue(
            $this->exists($justNow),
            'die Untergrenze von einer Stunde schützt den laufenden Upload',
        );
    }

    /**
     * Legt ein privates Medium an und datiert es per SQL zurück, weil die DAL `created_at`
     * beim Anlegen selbst auf jetzt setzt.
     */
    private function media(?string $folderId, string $age): string
    {
        $id = Uuid::randomHex();
        $data = ['id' => $id, 'private' => true];
        if ($folderId !== null) {
            $data['mediaFolderId'] = $folderId;
        }
        $this->mediaRepository->create([$data], $this->context);

        $this->connection->executeStatement(
            'UPDATE `media` SET created_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :versatz HOUR) WHERE id = :id',
            ['versatz' => $this->hours($age), 'id' => Uuid::fromHexToBytes($id)],
        );

        return $id;
    }

    private function createAttachment(string $mediaId, string $age): string
    {
        // Der Fremdschlüssel auf `order` verlangt eine echte Bestellung.
        $orderId = $this->createTestOrder();

        $id = Uuid::randomHex();
        $this->connection->insert('rc_order_attachment', [
            'id' => Uuid::fromHexToBytes($id),
            'order_id' => Uuid::fromHexToBytes($orderId),
            'order_version_id' => Uuid::fromHexToBytes(\Shopware\Core\Defaults::LIVE_VERSION),
            'media_id' => Uuid::fromHexToBytes($mediaId),
            'original_file_name' => 'gebunden.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1234,
            'created_at' => (new DateTimeImmutable($age))->format('Y-m-d H:i:s.v'),
        ]);

        return $id;
    }

    private function exists(string $mediaId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM `media` WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($mediaId)],
        );
    }

    private function hours(string $age): int
    {
        preg_match('/-?(\d+)\s*hour/', $age, $match);

        return -1 * (int) ($match[1] ?? 0);
    }
}
