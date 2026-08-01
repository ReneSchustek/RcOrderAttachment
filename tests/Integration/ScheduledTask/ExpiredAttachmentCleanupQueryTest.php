<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Integration\ScheduledTask;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcOrderAttachment\ScheduledTask\ExpiredAttachmentCleanupTaskHandler;
use Ruhrcoder\RcOrderAttachment\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Prüft die **Auswahl-Abfrage** des Aufbewahrungs-Cleanups an echten Daten.
 *
 * Der Handler arbeitet zweistufig, und beide Stufen können auf ihre Weise Schaden anrichten:
 *
 *   1. `created_at < cutoff AND order_version_id = LIVE` — wählt die abgelaufenen Anhänge.
 *      Ein Fehler an der Grenze löscht Bestelldaten zu früh.
 *   2. Danach werden Medien gelöscht, auf die **keine** Anhang-Zeile mehr zeigt.
 *      Ein Fehler hier reißt Dateien mit, die eine andere Bestellung noch braucht — der Fall
 *      entsteht real bei Cart-Splits, wo mehrere Positionen dieselbe Datei teilen.
 *
 * Unit-Tests konnten davon nur den Kontrollfluss prüfen; welche Zeilen die Abfragen liefern,
 * zeigt sich erst an einer echten Datenbank.
 */
final class ExpiredAttachmentCleanupQueryTest extends IntegrationTestCase
{
    private const AUFBEWAHRUNG_TAGE = 180;

    private Connection $connection;

    private ExpiredAttachmentCleanupTaskHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = static::connection();
        $this->handler = static::service(ExpiredAttachmentCleanupTaskHandler::class);

        static::systemConfig()
            ->set('RcOrderAttachment.config.attachmentRetentionDays', self::AUFBEWAHRUNG_TAGE);
    }

    /**
     * Was: Ein Anhang jenseits der Aufbewahrungsfrist, dessen Medium sonst niemand braucht.
     * Warum: Der Zweck der Routine — Datenminimierung nach Ablauf der Aufbewahrung.
     * Erwartet: Anhang und Medium verschwinden beide.
     */
    public function testDeletesExpiredAttachmentAndItsMedia(): void
    {
        $medium = $this->medium();
        $anhang = $this->anhang($medium, tageAlt: 200);

        $this->handler->run();

        self::assertFalse($this->anhangExistiert($anhang), 'der abgelaufene Anhang muss weg');
        self::assertFalse($this->mediumExistiert($medium), 'das nun unreferenzierte Medium ebenso');
    }

    /**
     * Was: Die Grenze — ein Anhang knapp jenseits, einer knapp diesseits der Frist.
     * Warum: Ein Fehler um einen Tag ist in der Abfrage nicht zu sehen und löscht Bestelldaten
     *        zu früh. Nur beidseitig geprüft steht fest, wo geschnitten wird.
     * Erwartet: der ältere weg, der jüngere bleibt.
     */
    public function testCutoffCutsOnTheRightSide(): void
    {
        $altesMedium = $this->medium();
        $altesAnhang = $this->anhang($altesMedium, tageAlt: 200);

        $jungesMedium = $this->medium();
        $jungerAnhang = $this->anhang($jungesMedium, tageAlt: 179);

        $this->handler->run();

        self::assertFalse($this->anhangExistiert($altesAnhang), 'älter als 180 Tage muss weg');
        self::assertTrue($this->anhangExistiert($jungerAnhang), 'jünger als 180 Tage muss bleiben');
        self::assertTrue($this->mediumExistiert($jungesMedium), 'und sein Medium auch');
    }

    /**
     * Was: Ein Medium, das sich zwei Anhänge teilen — einer abgelaufen, einer nicht.
     * Warum: **Der teuerste Fehler, den diese Routine machen könnte.** Bei einem Cart-Split zeigen
     *        mehrere Positionen auf dieselbe hochgeladene Datei. Löschte der Handler das Medium,
     *        sobald *ein* Anhang abläuft, verlöre die noch gültige Bestellung ihre Datei — und der
     *        Fremdschlüssel `ON DELETE CASCADE` risse gleich auch deren Anhang-Zeile mit.
     * Erwartet: Der abgelaufene Anhang geht, das Medium und der junge Anhang bleiben.
     */
    public function testKeepsSharedMediaWhileAnotherAttachmentStillNeedsIt(): void
    {
        $geteiltesMedium = $this->medium();
        $abgelaufen = $this->anhang($geteiltesMedium, tageAlt: 200);
        $nochGueltig = $this->anhang($geteiltesMedium, tageAlt: 10);

        $this->handler->run();

        self::assertFalse($this->anhangExistiert($abgelaufen), 'der abgelaufene Anhang geht');
        self::assertTrue(
            $this->mediumExistiert($geteiltesMedium),
            'das geteilte Medium muss bleiben, solange eine gültige Bestellung darauf zeigt',
        );
        self::assertTrue(
            $this->anhangExistiert($nochGueltig),
            'und damit auch der Anhang der anderen Bestellung — sonst hätte der Cascade zugeschlagen',
        );
    }

    /**
     * Was: Aufbewahrung auf 0 gestellt.
     * Warum: Bei den Anhängen bedeutet 0 ausdrücklich „nie löschen" (`attachmentRetentionEnabled()`).
     *        Das ist der Gegensatz zur Waisen-Frist, die eine Untergrenze von einer Stunde hat —
     *        wer beide verwechselt, löscht entweder zu viel oder nie.
     * Erwartet: Auch ein uralter Anhang bleibt.
     */
    public function testDoesNothingWhenRetentionIsDisabled(): void
    {
        static::systemConfig()
            ->set('RcOrderAttachment.config.attachmentRetentionDays', 0);

        $medium = $this->medium();
        $anhang = $this->anhang($medium, tageAlt: 3000);

        $this->handler->run();

        self::assertTrue($this->anhangExistiert($anhang), '0 Tage heißt: nie löschen');
        self::assertTrue($this->mediumExistiert($medium));
    }

    /**
     * Was: Ein abgelaufener Anhang, der an einer **anderen Order-Version** hängt.
     * Warum: Shopware führt Bestellungen versioniert; Entwürfe aus Admin-Bearbeitungen liegen unter
     *        einer eigenen Version. Die Abfrage filtert auf `order_version_id = LIVE`. Ohne den
     *        Filter würde die Aufräumung in Versionsstände greifen, die gar nicht die
     *        ausgelieferte Bestellung sind.
     * Erwartet: bleibt unangetastet.
     */
    public function testIgnoresAttachmentsOfNonLiveOrderVersions(): void
    {
        $orderId = $this->createTestOrder();
        $entwurfsVersion = static::repository('order.repository')
            ->createVersion($orderId, $this->context);

        $medium = $this->medium();
        $anhang = $this->anhangRoh($orderId, $entwurfsVersion, $medium, tageAlt: 200);

        $this->handler->run();

        self::assertTrue(
            $this->anhangExistiert($anhang),
            'nur die Live-Version der Bestellung wird aufgeräumt',
        );
    }

    // --- Hilfen ---------------------------------------------------------

    private function medium(): string
    {
        $id = Uuid::randomHex();
        static::repository('media.repository')
            ->create([['id' => $id, 'private' => true]], $this->context);

        return $id;
    }

    /**
     * Legt einen Anhang an einer frischen Bestellung an und datiert ihn zurück.
     */
    private function anhang(string $mediaId, int $tageAlt): string
    {
        return $this->anhangRoh($this->createTestOrder(), Defaults::LIVE_VERSION, $mediaId, $tageAlt);
    }

    private function anhangRoh(string $orderId, string $versionId, string $mediaId, int $tageAlt): string
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

        // Rückdatierung per SQL: Die Spalte wird beim Einfügen gesetzt, eine Vergangenheit kennt
        // die DAL nicht — und der Handler schneidet genau an dieser Spalte.
        $this->connection->executeStatement(
            'UPDATE `rc_order_attachment` SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL :tage DAY) WHERE id = :id',
            ['tage' => $tageAlt, 'id' => Uuid::fromHexToBytes($id)],
        );

        return $id;
    }

    private function anhangExistiert(string $id): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM `rc_order_attachment` WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($id)],
        );
    }

    private function mediumExistiert(string $id): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM `media` WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($id)],
        );
    }
}
