<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Validation;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Ruhrcoder\RcOrderAttachment\Service\Validation\DangerousContentValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ExtensionValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\FileCountValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\FileSizeValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\MagicBytesValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\MimeTypeValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\TotalSizeValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\UploadValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ValidationCode;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Unit-Tests für die Orchestrierung der Validierungs-Kette. Prüft die feste
 * Reihenfolge und die Early-Returns (PluginDisabled, UploadError) — die
 * Einzel-Validator-Tests stehen separat unter `ValidatorsTest`.
 */
final class UploadValidatorTest extends TestCase
{
    private int $counter = 0;

    /**
     * @var list<string>
     */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->cleanup = [];
    }

    /**
     * Was: Eine PDF-Datei mit korrekten Magic-Bytes und Default-Config.
     * Warum: Happy-Path — der häufigste Customer-Upload (Bestellbeleg, Zeichnung).
     *        Wenn er nicht durchgeht, ist die ganze Pipeline kaputt.
     * Erwartet: `isValid() = true`.
     */
    public function testHappyPathPdfPasses(): void
    {
        $validator = $this->buildValidator();
        $file = $this->makeFile('drawing.pdf', "%PDF-1.5\nrest-bytes" . str_repeat('a', 200));

        $result = $validator->validate($file, $this->config(), 0, 0);

        self::assertTrue($result->isValid(), implode(',', $result->getCodeValues()));
    }

    /**
     * Was: Plugin in der Config deaktiviert.
     * Warum: Erste Gate-Stufe — wenn das Plugin aus ist, muss die Validierung
     *        sofort mit `PLUGIN_DISABLED` brechen, ohne weitere Validatoren
     *        zu rufen (Performance, klare Fehlermeldung).
     * Erwartet: Genau ein Fail mit Code `PLUGIN_DISABLED` (Early-Return).
     */
    public function testRejectsWhenPluginDisabled(): void
    {
        $validator = $this->buildValidator();
        $file = $this->makeFile('drawing.pdf', '%PDF-1.5');

        $config = $this->config(enabled: false);

        $result = $validator->validate($file, $config, 0, 0);

        self::assertSame(ValidationCode::PLUGIN_DISABLED, $result->firstCode());
        self::assertCount(1, $result->getFailures(), 'PLUGIN_DISABLED muss die Kette abbrechen.');
    }

    /**
     * Was: Admin trägt versehentlich `php` in die Whitelist ein, Customer
     *      lädt eine PHP-Datei hoch.
     * Warum: Defense-in-Depth — die hart kodierte Blacklist im
     *        `DangerousContentValidator` muss das letzte Wort haben, sonst
     *        wäre RCE möglich.
     * Erwartet: Reject mit Code `extensionBlacklisted`.
     */
    public function testBlacklistOverridesWhitelist(): void
    {
        $validator = $this->buildValidator();
        $file = $this->makeFile('payload.php', "<?php echo 1;");

        // Admin hätte versehentlich .php in Whitelist eingetragen.
        $config = $this->config(allowed: ['php']);

        $result = $validator->validate($file, $config, 0, 0);

        self::assertFalse($result->isValid());
        self::assertContains('extensionBlacklisted', $result->getCodeValues());
    }

    /**
     * Was: Datei verletzt gleichzeitig Magic-Bytes (kein PDF-Header) und
     *      Größen-Limit.
     * Warum: UX-Pflicht — Customer soll alle Fehler einer Datei in einem
     *        Roundtrip sehen, statt mehrere Anläufe machen zu müssen.
     * Erwartet: Codes enthalten `magicBytesMismatch` UND `fileSizeExceeded`.
     */
    public function testCollectsAllFailuresInOneRoundtrip(): void
    {
        $validator = $this->buildValidator();
        // Endung erlaubt, aber Inhalt zu groß UND falsche Magic Bytes
        $file = $this->makeFile('big.pdf', str_repeat('X', 10 * 1024));
        $config = $this->config(maxFileSizeKb: 2);

        $result = $validator->validate($file, $config, 0, 0);

        $codes = $result->getCodeValues();
        self::assertContains('magicBytesMismatch', $codes);
        self::assertContains('fileSizeExceeded', $codes);
    }

    private function buildValidator(): UploadValidator
    {
        return new UploadValidator(
            new ExtensionValidator(),
            new DangerousContentValidator(),
            new MimeTypeValidator(),
            new MagicBytesValidator(),
            new FileSizeValidator(),
            new TotalSizeValidator(),
            new FileCountValidator(),
        );
    }

    private function makeFile(string $name, string $contents): UploadedFile
    {
        $dir = sys_get_temp_dir() . '/rc-order-attachment-upload-validator-tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $path = $dir . '/test-' . (++$this->counter) . '-' . $name;
        file_put_contents($path, $contents);
        $this->cleanup[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @param list<string> $allowed
     */
    private function config(
        bool $enabled = true,
        bool $required = false,
        int $maxFiles = 5,
        int $maxFileSizeKb = 5120,
        int $maxTotalSizeKb = 20480,
        array $allowed = ['pdf', 'jpg', 'png'],
    ): PluginConfig {
        return new PluginConfig(
            enabled: $enabled,
            required: $required,
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
