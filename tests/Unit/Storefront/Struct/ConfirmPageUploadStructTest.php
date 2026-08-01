<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Storefront\Struct;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUpload;
use Ruhrcoder\RcOrderAttachment\Storefront\Struct\ConfirmPageUploadStruct;

/**
 * Unit-Tests für den Confirm-Page-Twig-Datenträger. Prüft die Hilfsberechnungen
 * (Remaining-Counter, Accept-Attribut, Has-Uploads-Flag), die das Template nutzt.
 */
final class ConfirmPageUploadStructTest extends TestCase
{
    /**
     * Was: Struct mit zwei Uploads (3 KB + 2 KB), Limits maxFiles=3, totalKb=10.
     * Warum: Twig zeigt dem Customer „noch X Dateien / Y Bytes verfügbar" —
     *        die Berechnung muss exakt sein, sonst sieht der Customer falsche
     *        Grenzen.
     * Erwartet: `hasUploads()=true`, `remainingFiles=1`, `remainingBytes=10*1024-5120`.
     */
    public function testRemainingCounters(): void
    {
        $config = $this->config(maxFiles: 3, maxTotalSizeKb: 10);
        $uploads = [
            $this->upload('a', 3072),
            $this->upload('b', 2048),
        ];

        $struct = new ConfirmPageUploadStruct($config, $uploads);

        self::assertTrue($struct->hasUploads());
        self::assertSame(1, $struct->getRemainingFiles());
        self::assertSame(10 * 1024 - 5120, $struct->getRemainingBytes());
    }

    /**
     * Was: `getAcceptAttribute` und `getAllowedExtensionsCsv` mit Default-Endungen.
     * Warum: Das `accept=`-Attribut im File-Input lenkt das OS-File-Picker-Dialog
     *        — falscher Format-String → kein Dialog-Filter oder Browser-
     *        Inkompatibilität. CSV ist für UI-Anzeige.
     * Erwartet: `.pdf,.jpg,.png` (mit führendem Punkt, ohne Whitespace) und
     *           `pdf, jpg, png` (mit Komma+Space).
     */
    public function testAcceptAttribute(): void
    {
        $struct = new ConfirmPageUploadStruct(
            $this->config(allowed: ['pdf', 'jpg', 'png']),
            [],
        );

        self::assertSame('.pdf,.jpg,.png', $struct->getAcceptAttribute());
        self::assertSame('pdf, jpg, png', $struct->getAllowedExtensionsCsv());
    }

    /**
     * Was: Limit überschritten (1 Datei + 10 KB statt 1 KB).
     * Warum: Negative Werte in `remainingFiles`/`remainingBytes` würden im
     *        Twig-Template unsinnige Anzeigen erzeugen („noch -9216 Bytes
     *        verfügbar"). Müssen auf 0 geclampt sein.
     * Erwartet: Beide Counter sind 0.
     */
    public function testRemainingClampedAtZero(): void
    {
        $struct = new ConfirmPageUploadStruct(
            $this->config(maxFiles: 1, maxTotalSizeKb: 1),
            [$this->upload('a', 10 * 1024)],
        );

        self::assertSame(0, $struct->getRemainingFiles());
        self::assertSame(0, $struct->getRemainingBytes());
    }

    private function upload(string $token, int $size): PendingUpload
    {
        return new PendingUpload($token, 'media-' . $token, 'name-' . $token . '.pdf', 'application/pdf', $size, 0);
    }

    /**
     * @param list<string> $allowed
     */
    private function config(
        bool $enabled = true,
        int $maxFiles = 5,
        int $maxFileSizeKb = 5120,
        int $maxTotalSizeKb = 20480,
        array $allowed = ['pdf'],
    ): PluginConfig {
        return new PluginConfig(
            enabled: $enabled,
            required: false,
            maxFiles: $maxFiles,
            maxFileSizeKb: $maxFileSizeKb,
            maxTotalSizeKb: $maxTotalSizeKb,
            allowedExtensions: $allowed,
            orphanRetentionHours: 24,
            attachmentRetentionDays: 180,
            attachToConfirmationMail: true,
            stripExifFromImages: true,
            rateLimitPerMinute: 30,
            mailTemplateWhitelist: [],
        );
    }
}
