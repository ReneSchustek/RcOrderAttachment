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
 * Prüft die **Auswahl-Abfrage** des Orphan-Cleanups an echten Daten.
 *
 * Der Handler löscht private Kunden-Uploads. Die Unit-Tests decken ab, was er mit einem Ergebnis
 * tut; welche Zeilen seine Abfrage liefert, konnten sie nicht prüfen — ein Mock gibt zurück, was
 * der Test vorgibt. Genau das holt dieser Test nach.
 *
 * Die Abfrage hat drei Bedingungen, und jede einzelne trägt Verantwortung:
 *
 *   media_folder_id = <Plugin-Ordner>   → fremde Medien bleiben unangetastet
 *   created_at      < cutoff            → laufende Uploads bleiben unangetastet
 *   a.id IS NULL    (LEFT JOIN)         → an Bestellungen gebundene Dateien bleiben unangetastet
 *
 * Fiele eine davon aus, würde der Handler Dateien löschen, die jemandem gehören.
 */
final class OrphanedAttachmentCleanupQueryTest extends IntegrationTestCase
{
    private const AUFBEWAHRUNG_STUNDEN = 24;

    private Connection $connection;

    /** @var EntityRepository<\Shopware\Core\Content\Media\MediaCollection> */
    private EntityRepository $mediaRepository;

    private OrphanedAttachmentCleanupTaskHandler $handler;

    private string $pluginOrdnerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = static::connection();
        $mediaRepository = static::repository('media.repository');
        /** @var EntityRepository<MediaCollection> $mediaRepository */
        $this->mediaRepository = $mediaRepository;
        $this->handler = static::service(OrphanedAttachmentCleanupTaskHandler::class);

        static::systemConfig()
            ->set('RcOrderAttachment.config.orphanRetentionHours', self::AUFBEWAHRUNG_STUNDEN);

        $installer = static::service(MediaFolderInstaller::class);
        $ordnerId = $installer->findFolderId($this->context) ?? $installer->ensureFolder($this->context);
        $this->pluginOrdnerId = $ordnerId;
    }

    /**
     * Was: Eine verwaiste Datei im Plugin-Ordner, älter als die Aufbewahrungsfrist.
     * Warum: Der Fall, für den der Handler gebaut ist — ein Upload, den niemand bestellt hat.
     * Erwartet: gelöscht.
     */
    public function testDeletesOrphanOlderThanRetention(): void
    {
        $verwaist = $this->medium($this->pluginOrdnerId, '-25 hours');

        $this->handler->run();

        self::assertFalse($this->existiert($verwaist), 'ein echter Waise muss verschwinden');
    }

    /**
     * Was: Eine Datei im Plugin-Ordner, an die eine Bestellung gebunden ist — obwohl sie alt ist.
     * Warum: **Der wichtigste Test dieser Datei.** Fiele der LEFT-JOIN weg, löschte der Handler
     *        die Anhänge abgeschlossener Bestellungen. Der Kunde hätte hochgeladen, der Betrieb
     *        hätte nichts mehr.
     * Erwartet: bleibt.
     */
    public function testKeepsMediaReferencedByAnAttachment(): void
    {
        $gebunden = $this->medium($this->pluginOrdnerId, '-25 hours');
        $this->anhangAnlegen($gebunden, '-25 hours');

        $this->handler->run();

        self::assertTrue(
            $this->existiert($gebunden),
            'an eine Bestellung gebundene Dateien duerfen der Waisen-Suche nie ins Netz gehen',
        );
    }

    /**
     * Was: Eine Datei, die jünger ist als die Frist.
     * Warum: Zwischen Upload auf der Bestätigungsseite und dem Absenden der Bestellung liegt Zeit.
     *        Griffe der Handler zu früh, verschwände die Datei aus dem laufenden Bestellvorgang.
     * Erwartet: bleibt.
     */
    public function testKeepsMediaYoungerThanRetention(): void
    {
        $frisch = $this->medium($this->pluginOrdnerId, '-1 hour');

        $this->handler->run();

        self::assertTrue($this->existiert($frisch), 'ein laufender Upload darf nicht abgeraeumt werden');
    }

    /**
     * Was: Die Grenze selbst — eine Datei knapp diesseits, eine knapp jenseits der Frist.
     * Warum: Ein Fehler um eine Stunde ist in der Abfrage unsichtbar und im Betrieb teuer. Nur der
     *        beidseitige Test nagelt fest, wo geschnitten wird.
     * Erwartet: die ältere weg, die jüngere bleibt.
     */
    public function testCutoffCutsOnTheRightSide(): void
    {
        $knappDrueber = $this->medium($this->pluginOrdnerId, '-25 hours');
        $knappDrunter = $this->medium($this->pluginOrdnerId, '-23 hours');

        $this->handler->run();

        self::assertFalse($this->existiert($knappDrueber), 'aelter als 24 h muss weg');
        self::assertTrue($this->existiert($knappDrunter), 'juenger als 24 h muss bleiben');
    }

    /**
     * Was: Eine alte, unreferenzierte Datei in einem **fremden** Ordner.
     * Warum: Der Ordner-Filter ist die Grenze zwischen „unsere Dateien" und dem restlichen
     *        Medienbestand des Shops. Ohne ihn räumte das Plugin fremde Medien ab — Produktbilder,
     *        Theme-Dateien, Dokumente.
     * Erwartet: bleibt.
     */
    public function testNeverTouchesMediaOutsideThePluginFolder(): void
    {
        $fremd = $this->medium(null, '-25 hours');

        $this->handler->run();

        self::assertTrue($this->existiert($fremd), 'ausserhalb des Plugin-Ordners hat der Handler nichts zu suchen');
    }

    /**
     * Was: Aufbewahrung auf 0 gestellt — der aggressivste Wert, den die Oberfläche zulässt.
     * Warum: Anders als bei der Anhang-Aufbewahrung (0 = nie löschen) lässt sich die Waisen-Frist
     *        **nicht abschalten**: `PluginConfigProvider` hebt sie mit `max(1, …)` auf eine Stunde
     *        an. Das ist die Schutzschwelle für laufende Bestellvorgänge — ohne sie löschte der
     *        nächste Lauf eine Datei, die der Kunde gerade hochgeladen hat. Fällt das `max(1, …)`
     *        weg, fällt dieser Test.
     *
     *        Meine erste Fassung erwartete hier „0 = aus" und war rot. Das Verhalten ist richtig,
     *        die Erwartung war es nicht.
     * Erwartet: Alte Waisen verschwinden, eine Datei aus der laufenden Stunde bleibt.
     */
    public function testRetentionIsClampedToAtLeastOneHour(): void
    {
        static::systemConfig()
            ->set('RcOrderAttachment.config.orphanRetentionHours', 0);

        $alt = $this->medium($this->pluginOrdnerId, '-100 hours');
        $geradeEben = $this->medium($this->pluginOrdnerId, '-0 hours');

        $this->handler->run();

        self::assertFalse($this->existiert($alt), 'alte Waisen werden auch bei 0 abgeraeumt');
        self::assertTrue(
            $this->existiert($geradeEben),
            'die Untergrenze von einer Stunde schuetzt den laufenden Upload',
        );
    }

    // --- Hilfen ---------------------------------------------------------

    /**
     * Legt ein privates Medium an und datiert es per SQL zurück — die DAL setzt `created_at`
     * selbst und kennt keine Vergangenheit.
     */
    private function medium(?string $ordnerId, string $alter): string
    {
        $id = Uuid::randomHex();
        $daten = ['id' => $id, 'private' => true];
        if ($ordnerId !== null) {
            $daten['mediaFolderId'] = $ordnerId;
        }
        $this->mediaRepository->create([$daten], $this->context);

        $this->connection->executeStatement(
            'UPDATE `media` SET created_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :versatz HOUR) WHERE id = :id',
            ['versatz' => $this->stunden($alter), 'id' => Uuid::fromHexToBytes($id)],
        );

        return $id;
    }

    private function anhangAnlegen(string $mediaId, string $alter): string
    {
        // Echte Bestellung: Der Fremdschlüssel auf `order` lässt nichts anderes zu — und ein
        // Anhang ohne Bestellung wäre ohnehin genau der Waise, den dieser Test NICHT prüfen will.
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
            'created_at' => (new DateTimeImmutable($alter))->format('Y-m-d H:i:s.v'),
        ]);

        return $id;
    }

    private function existiert(string $mediaId): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM `media` WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($mediaId)],
        );
    }

    private function stunden(string $alter): int
    {
        preg_match('/-?(\d+)\s*hour/', $alter, $treffer);

        return -1 * (int) ($treffer[1] ?? 0);
    }
}
