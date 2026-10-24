<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Integration\ScheduledTask;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcOrderAttachment\ScheduledTask\ExpiredAttachmentCleanupTaskHandler;
use Ruhrcoder\RcOrderAttachment\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft die Auswahlabfragen der Aufbewahrungsbereinigung an einer echten Datenbank, denn die
 * Unit-Tests sehen nur den Kontrollfluss und nicht, welche Zeilen die Abfragen liefern.
 *
 * Der Handler arbeitet in zwei Stufen. Zuerst wählt `created_at < cutoff AND order_version_id = LIVE`
 * die abgelaufenen Anhänge; ein Fehler an der Grenze löscht Bestelldaten zu früh. Danach löscht er
 * Medien, auf die keine Anhangszeile mehr zeigt; ein Fehler dort reißt Dateien mit, die eine andere
 * Bestellung noch braucht, etwa wenn sich nach einer Aufteilung des Warenkorbs mehrere Anhänge
 * dieselbe Datei teilen.
 */
final class ExpiredAttachmentCleanupQueryTest extends IntegrationTestCase
{
    // Entspricht der Vorgabe aus config.xml; die Grenzfälle 179 und 200 Tage liegen beiderseits.
    private const RETENTION_DAYS = 180;

    private Connection $connection;

    private ExpiredAttachmentCleanupTaskHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = static::connection();
        $this->handler = static::service(ExpiredAttachmentCleanupTaskHandler::class);

        static::systemConfig()
            ->set('RcOrderAttachment.config.attachmentRetentionDays', self::RETENTION_DAYS);
    }

    /**
     * Der Regelfall der Datenminimierung: Nach Ablauf der Frist verschwinden Anhang und Medium,
     * wenn niemand sonst das Medium braucht.
     */
    public function testDeletesExpiredAttachmentAndItsMedia(): void
    {
        $media = $this->media();
        $attachment = $this->attachment($media, daysOld: 200);

        $this->handler->run();

        self::assertFalse($this->attachmentExists($attachment), 'der abgelaufene Anhang muss weg');
        self::assertFalse($this->mediaExists($media), 'das nun unreferenzierte Medium ebenso');
    }

    /**
     * Ein Fehler um einen Tag ist in der Abfrage nicht zu sehen und löscht Bestelldaten zu früh.
     * Erst ein Anhang auf jeder Seite der Frist legt fest, wo geschnitten wird.
     */
    public function testCutoffCutsOnTheRightSide(): void
    {
        $oldMedia = $this->media();
        $oldAttachment = $this->attachment($oldMedia, daysOld: 200);

        $youngMedia = $this->media();
        $youngAttachment = $this->attachment($youngMedia, daysOld: 179);

        $this->handler->run();

        self::assertFalse($this->attachmentExists($oldAttachment), 'älter als 180 Tage muss weg');
        self::assertTrue($this->attachmentExists($youngAttachment), 'jünger als 180 Tage muss bleiben');
        self::assertTrue($this->mediaExists($youngMedia), 'und sein Medium auch');
    }

    /**
     * Der teuerste Fehler, den die Routine machen kann: Teilen sich zwei Anhänge ein Medium und
     * löscht der Handler es, sobald einer abläuft, verliert die noch gültige Bestellung ihre Datei.
     * Der Fremdschlüssel mit `ON DELETE CASCADE` risse dabei auch deren Anhangszeile mit.
     */
    public function testKeepsSharedMediaWhileAnotherAttachmentStillNeedsIt(): void
    {
        $sharedMedia = $this->media();
        $expired = $this->attachment($sharedMedia, daysOld: 200);
        $stillValid = $this->attachment($sharedMedia, daysOld: 10);

        $this->handler->run();

        self::assertFalse($this->attachmentExists($expired), 'der abgelaufene Anhang geht');
        self::assertTrue(
            $this->mediaExists($sharedMedia),
            'das geteilte Medium muss bleiben, solange eine gültige Bestellung darauf zeigt',
        );
        self::assertTrue(
            $this->attachmentExists($stillValid),
            'und damit auch der Anhang der anderen Bestellung — sonst hätte der Cascade zugeschlagen',
        );
    }

    /**
     * Bei den Anhängen bedeutet 0 Tage „nie löschen" (`attachmentRetentionEnabled()`), bei der
     * Waisenfrist dagegen hebt der Konfigurationsleser 0 auf eine Stunde an. Wer beides
     * verwechselt, löscht entweder zu viel oder nie.
     */
    public function testDoesNothingWhenRetentionIsDisabled(): void
    {
        static::systemConfig()
            ->set('RcOrderAttachment.config.attachmentRetentionDays', 0);

        $media = $this->media();
        $attachment = $this->attachment($media, daysOld: 3000);

        $this->handler->run();

        self::assertTrue($this->attachmentExists($attachment), '0 Tage heißt: nie löschen');
        self::assertTrue($this->mediaExists($media));
    }

    /**
     * Shopware führt Bestellungen versioniert, Entwürfe aus der Bearbeitung in der Verwaltung
     * liegen unter einer eigenen Version. Ohne den Filter `order_version_id = LIVE` griffe die
     * Bereinigung in Stände, die gar nicht die ausgelieferte Bestellung sind.
     */
    public function testIgnoresAttachmentsOfNonLiveOrderVersions(): void
    {
        $orderId = $this->createTestOrder();
        $draftVersion = static::repository('order.repository')
            ->createVersion($orderId, $this->context);

        $media = $this->media();
        $attachment = $this->attachmentRaw($orderId, $draftVersion, $media, daysOld: 200);

        $this->handler->run();

        self::assertTrue(
            $this->attachmentExists($attachment),
            'nur die Live-Version der Bestellung wird aufgeräumt',
        );
    }

    private function media(): string
    {
        $id = Uuid::randomHex();
        static::repository('media.repository')
            ->create([['id' => $id, 'private' => true]], $this->context);

        return $id;
    }

    /**
     * Legt einen Anhang an einer frischen Bestellung an und datiert ihn zurück.
     */
    private function attachment(string $mediaId, int $daysOld): string
    {
        return $this->attachmentRaw($this->createTestOrder(), Defaults::LIVE_VERSION, $mediaId, $daysOld);
    }

    private function attachmentRaw(string $orderId, string $versionId, string $mediaId, int $daysOld): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('rc_order_attachment', [
            'id' => Uuid::fromHexToBytes($id),
            'order_id' => Uuid::fromHexToBytes($orderId),
            'order_version_id' => Uuid::fromHexToBytes($versionId),
            'media_id' => Uuid::fromHexToBytes($mediaId),
            'original_file_name' => 'zeichnung.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1234,
            'created_at' => '2020-01-01 00:00:00.000',
        ]);

        // Das Alter wird relativ zur aktuellen Zeit gesetzt, weil der Handler seine Grenze ebenfalls
        // von jetzt aus rechnet. Der feste Wert beim Einfügen füllt nur die Pflichtspalte.
        $this->connection->executeStatement(
            'UPDATE `rc_order_attachment` SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL :tage DAY) WHERE id = :id',
            ['tage' => $daysOld, 'id' => Uuid::fromHexToBytes($id)],
        );

        return $id;
    }

    private function attachmentExists(string $id): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM `rc_order_attachment` WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($id)],
        );
    }

    private function mediaExists(string $id): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM `media` WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($id)],
        );
    }
}
