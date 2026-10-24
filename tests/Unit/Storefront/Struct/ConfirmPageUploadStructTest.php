<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Storefront\Struct;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUpload;
use Ruhrcoder\RcOrderAttachment\Storefront\Struct\ConfirmPageUploadStruct;

/**
 * Prüft die Hilfswerte, die das Template der Bestätigungsseite anzeigt: verbleibende Dateien und
 * Bytes, das `accept`-Attribut und die Liste der Endungen. Falsche Werte zeigten dem Kunden
 * Grenzen, die der Server anders durchsetzt.
 */
final class ConfirmPageUploadStructTest extends TestCase
{
    /**
     * Das Template zeigt „noch X Dateien und Y Byte frei". Bei drei erlaubten Dateien und 10 KB
     * bleiben nach 3 KB und 2 KB eine Datei und 5 KB.
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
     * Das `accept`-Attribut filtert den Dateidialog und verlangt Endungen mit Punkt ohne
     * Leerzeichen; mit falschem Format filtert der Dialog nichts. Die zweite Fassung ist nur
     * für die Anzeige.
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
     * Grenzfall: Liegt der Bestand über der Grenze, hier 10 KB bei erlaubtem 1 KB, stünde sonst
     * „noch -9216 Byte frei" im Template. Beide Werte bleiben bei 0 stehen.
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
