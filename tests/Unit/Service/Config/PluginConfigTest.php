<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Config;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;

/**
 * Unit-Tests für das immutable Plugin-Config-Struct: KB→Byte-Konvertierung,
 * Whitelist-Check, Retention-Flag. Provider-eigene Tests liegen separat.
 */
final class PluginConfigTest extends TestCase
{
    /**
     * Was: Konvertierung von KB-Werten in Bytes via `maxFileSizeBytes()` /
     *      `maxTotalSizeBytes()`.
     * Warum: Validatoren rechnen in Bytes (Symfony's `UploadedFile::getSize()`),
     *        Konfig speichert KB. Eine fehlerhafte Konvertierung (z. B. 1000
     *        statt 1024) macht das Limit unscharf.
     * Erwartet: 1024 KB → 1024*1024 Bytes; 2048 KB → 2048*1024 Bytes.
     */
    public function testByteConversion(): void
    {
        $config = $this->build(maxFileSizeKb: 1024, maxTotalSizeKb: 2048);

        self::assertSame(1024 * 1024, $config->maxFileSizeBytes());
        self::assertSame(2048 * 1024, $config->maxTotalSizeBytes());
    }

    /**
     * Was: `isExtensionAllowed` mit Groß-/Kleinschreibung.
     * Warum: Datei-Endungen kommen aus Browser-Uploads in beliebiger Case.
     *        Case-sensitive Vergleich würde `IMG.JPG` ablehnen, obwohl `jpg`
     *        whitelisted ist.
     * Erwartet: `pdf`, `PDF`, `Pdf` alle true; `jpg` (nicht whitelisted) false.
     */
    public function testExtensionMatchIsCaseInsensitive(): void
    {
        $config = $this->build(allowedExtensions: ['pdf', 'png']);

        self::assertTrue($config->isExtensionAllowed('pdf'));
        self::assertTrue($config->isExtensionAllowed('PDF'));
        self::assertTrue($config->isExtensionAllowed('Pdf'));
        self::assertFalse($config->isExtensionAllowed('jpg'));
    }

    /**
     * Was: `attachmentRetentionEnabled()` bei 0-Tagen und 30-Tagen.
     * Warum: Service-Code prüft mit dieser Methode, ob der Retention-Cron etwas
     *        tun soll. Eine kaputte Logik würde entweder dauerhaft löschen
     *        (Wert 0 fälschlich „aktiv") oder gar nicht löschen.
     * Erwartet: 0 Tage → false, 30 Tage → true.
     */
    public function testRetentionDisabledAtZero(): void
    {
        self::assertFalse($this->build(attachmentRetentionDays: 0)->attachmentRetentionEnabled());
        self::assertTrue($this->build(attachmentRetentionDays: 30)->attachmentRetentionEnabled());
    }

    /**
     * @param list<string> $allowedExtensions
     * @param list<string> $mailTemplateWhitelist
     */
    private function build(
        bool $enabled = true,
        bool $required = false,
        int $maxFiles = 5,
        int $maxFileSizeKb = 5120,
        int $maxTotalSizeKb = 20480,
        array $allowedExtensions = ['pdf', 'jpg', 'png'],
        int $orphanRetentionHours = 24,
        int $attachmentRetentionDays = 180,
        bool $attachToConfirmationMail = true,
        bool $stripExifFromImages = true,
        int $rateLimitPerMinute = 30,
        array $mailTemplateWhitelist = [],
    ): PluginConfig {
        return new PluginConfig(
            enabled: $enabled,
            required: $required,
            maxFiles: $maxFiles,
            maxFileSizeKb: $maxFileSizeKb,
            maxTotalSizeKb: $maxTotalSizeKb,
            allowedExtensions: $allowedExtensions,
            orphanRetentionHours: $orphanRetentionHours,
            attachmentRetentionDays: $attachmentRetentionDays,
            attachToConfirmationMail: $attachToConfirmationMail,
            stripExifFromImages: $stripExifFromImages,
            rateLimitPerMinute: $rateLimitPerMinute,
            mailTemplateWhitelist: $mailTemplateWhitelist,
        );
    }
}
