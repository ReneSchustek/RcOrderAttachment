<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Config;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;

/**
 * Prüft die abgeleiteten Werte der unveränderlichen Konfiguration: Umrechnung von KB in Byte,
 * Endungsprüfung und Schalter der Aufbewahrung. Jeder Validator und die Bereinigung verlassen
 * sich darauf; das Lesen und Bereinigen prüft `PluginConfigProviderTest`.
 */
final class PluginConfigTest extends TestCase
{
    /**
     * Die Validatoren rechnen in Byte (`UploadedFile::getSize()`), die Konfiguration speichert KB.
     * Mit 1000 statt 1024 läge die Grenze rund zwei Prozent zu niedrig.
     */
    public function testByteConversion(): void
    {
        $config = $this->build(maxFileSizeKb: 1024, maxTotalSizeKb: 2048);

        self::assertSame(1024 * 1024, $config->maxFileSizeBytes());
        self::assertSame(2048 * 1024, $config->maxTotalSizeBytes());
    }

    /**
     * Was: Die Grenzen, wie der Kunde sie in der Kasse liest.
     * Warum: „10240 KB" muss ein Kunde erst umrechnen; „10 MB" versteht er sofort.
     */
    public function testSizeLabelsAreReadable(): void
    {
        self::assertSame('10 MB', $this->build(maxFileSizeKb: 10240)->maxFileSizeLabel());
        self::assertSame('40 MB', $this->build(maxTotalSizeKb: 40960)->maxTotalSizeLabel());
        self::assertSame('1,5 MB', $this->build(maxFileSizeKb: 1536)->maxFileSizeLabel());
        self::assertSame('500 KB', $this->build(maxTotalSizeKb: 500)->maxTotalSizeLabel());
    }

    /**
     * Endungen kommen in beliebiger Schreibweise aus dem Browser. Ein Vergleich mit
     * Groß- und Kleinschreibung wiese `IMG.JPG` ab, obwohl `jpg` erlaubt ist.
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
     * Die Aufbewahrungsbereinigung fragt diese Methode, ob sie laufen soll. Gälte 0 als aktiv,
     * löschte sie mit einer Frist von null Tagen jeden Anhang; gälte 30 als aus, löschte sie nie.
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
