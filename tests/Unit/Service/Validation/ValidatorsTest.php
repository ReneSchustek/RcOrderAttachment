<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Ruhrcoder\RcOrderAttachment\Service\Validation\DangerousContentValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ExtensionValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\FileCountValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\FileSizeValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\MagicBytesValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\TotalSizeValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ValidationCode;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ValidationResult;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Prüft die einzelnen Validatoren der Uploadkette: Freigabeliste der Endungen, Sperrliste samt
 * Skriptkennungen im Inhalt, Dateisignaturen sowie Grenzen für Größe, Gesamtgröße und Anzahl.
 * Jeder schließt eine eigene Lücke, durch die sonst Schadcode oder Massenuploads kämen. Das
 * Zusammenspiel prüft {@see UploadValidatorTest}.
 */
final class ValidatorsTest extends TestCase
{
    private string $tempDir;

    private int $fileCounter = 0;

    /**
     * @var list<string>
     */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        // Die Prozess-ID steht im Pfad, weil der Zähler je Lauf bei 0 beginnt. Parallele Läufe
        // (CI-Matrix, `--processes`) kämen sich in einem gemeinsamen Verzeichnis sonst in die Quere.
        $this->tempDir = sys_get_temp_dir() . '/rc-order-attachment-tests-' . getmypid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0o755, true);
        }
        $this->fileCounter = 0;
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->createdFiles = [];
    }

    /**
     * Der Standardfall: `pdf` steht in der Freigabeliste und geht durch.
     */
    public function testExtensionWhitelistAccepts(): void
    {
        $result = new ValidationResult();
        (new ExtensionValidator())->validate(
            $this->file('drawing.pdf', '%PDF-1.4'),
            $this->config(allowed: ['pdf']),
            $result,
        );

        self::assertTrue($result->isValid());
    }

    /**
     * Was nicht in der Freigabeliste steht, wird abgewiesen, hier eine `.exe`.
     */
    public function testExtensionWhitelistRejects(): void
    {
        $result = new ValidationResult();
        (new ExtensionValidator())->validate(
            $this->file('drawing.exe', 'MZ'),
            $this->config(allowed: ['pdf']),
            $result,
        );

        self::assertFalse($result->isValid());
        self::assertSame(ValidationCode::EXTENSION_NOT_ALLOWED, $result->firstCode());
    }

    /**
     * Ohne Endung lässt sich der Typ nicht zuverlässig zuordnen. Eine solche Datei wird
     * abgewiesen, statt sie durchzuwinken.
     */
    public function testExtensionEmptyRejects(): void
    {
        $result = new ValidationResult();
        (new ExtensionValidator())->validate(
            $this->file('noext', 'x'),
            $this->config(allowed: ['pdf']),
            $result,
        );

        self::assertSame(ValidationCode::EXTENSION_NOT_ALLOWED, $result->firstCode());
    }

    /**
     * Die fest eingebaute Sperrliste greift bei jeder einzelnen Endung, auch wenn sie in der
     * Freigabeliste stünde und der Inhalt harmlos ist.
     */
    #[DataProvider('provideForbiddenExtensions')]
    public function testDangerousExtensionBlacklisted(string $extension): void
    {
        $result = new ValidationResult();
        (new DangerousContentValidator())->validate(
            $this->file('payload.' . $extension, 'content'),
            $result,
        );

        self::assertSame(ValidationCode::EXTENSION_BLACKLISTED, $result->firstCode(), $extension);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function provideForbiddenExtensions(): array
    {
        return [
            ['php'], ['phtml'], ['phar'], ['htaccess'], ['sh'], ['exe'],
            ['bat'], ['cmd'], ['ps1'], ['vbs'], ['js'], ['jsp'], ['asp'],
            ['aspx'], ['html'], ['htm'], ['svg'], ['hta'], ['dll'], ['msi'],
        ];
    }

    /**
     * PHP-Code in einer Datei, die sich als PDF ausgibt, fällt an der Skriptkennung auf. Gesucht
     * wird in den ersten 2 KiB.
     */
    public function testDangerousContentDetectsPhpTag(): void
    {
        $result = new ValidationResult();
        (new DangerousContentValidator())->validate(
            $this->file('innocent.pdf', "<?php echo 'pwn'; ?>"),
            $result,
        );

        self::assertSame(ValidationCode::DANGEROUS_CONTENT_DETECTED, $result->firstCode());
    }

    /**
     * Ein Skriptblock könnte in einem Betrachter, der HTML deutet, ausgeführt werden. Die Suche
     * unterscheidet nicht nach Groß- und Kleinschreibung, deshalb steht hier `<SCRIPT>`.
     */
    public function testDangerousContentDetectsScriptTag(): void
    {
        // Der PDF-Kopf vorneweg hält finfo davon ab, `text/html` zu erkennen. Sonst wiese die
        // MIME-Prüfung im selben Validator die Datei zuerst ab, und die Skriptkennung bliebe ungeprüft.
        $payload = "%PDF-1.4\n%fake\n<SCRIPT>alert(1)</SCRIPT>";

        $result = new ValidationResult();
        (new DangerousContentValidator())->validate(
            $this->file('innocent.pdf', $payload),
            $result,
        );

        self::assertSame(ValidationCode::DANGEROUS_CONTENT_DETECTED, $result->firstCode());
    }

    /**
     * Ein Shell-Skript, das sich als anderer Dateityp tarnt, fällt an der Kennung `#!/` auf.
     */
    public function testDangerousContentDetectsShebang(): void
    {
        $result = new ValidationResult();
        (new DangerousContentValidator())->validate(
            $this->file('innocent.pdf', "#!/bin/bash\nrm -rf /"),
            $result,
        );

        self::assertSame(ValidationCode::DANGEROUS_CONTENT_DETECTED, $result->firstCode());
    }

    /**
     * Ein echtes PDF mit `%PDF`-Kopf geht durch.
     */
    public function testMagicBytesPdfValid(): void
    {
        $result = new ValidationResult();
        (new MagicBytesValidator())->validate(
            $this->file('drawing.pdf', '%PDF-1.7' . str_repeat('A', 1000)),
            $result,
        );

        self::assertTrue($result->isValid());
    }

    /**
     * Die Endung allein reicht nicht, die Signatur am Dateianfang muss den Typ bestätigen.
     */
    public function testMagicBytesPdfMismatch(): void
    {
        $result = new ValidationResult();
        (new MagicBytesValidator())->validate(
            $this->file('drawing.pdf', 'this is not a pdf at all'),
            $result,
        );

        self::assertSame(ValidationCode::MAGIC_BYTES_MISMATCH, $result->firstCode());
    }

    /**
     * PNG ist neben JPEG das gängigste Fotoformat. Seine Signatur ist acht Byte lang und
     * enthält `\r\n` und `\x1a`; ein echter Kopf muss trotzdem als Ganzes erkannt werden.
     */
    public function testMagicBytesPng(): void
    {
        $pngHeader = "\x89PNG\r\n\x1a\n";
        $result = new ValidationResult();
        (new MagicBytesValidator())->validate(
            $this->file('img.png', $pngHeader . 'rest'),
            $result,
        );

        self::assertTrue($result->isValid());
    }

    /**
     * Endungen ohne feste Signatur wie DXF, TXT oder CSV lehnt der Validator nicht ab, sonst
     * wären sie trotz Freigabe unbenutzbar.
     */
    public function testMagicBytesUnknownExtensionPasses(): void
    {
        $result = new ValidationResult();
        (new MagicBytesValidator())->validate(
            $this->file('drawing.dxf', 'CAD-Reintext'),
            $result,
        );

        self::assertTrue($result->isValid());
    }

    /**
     * 1 KB unter einer Grenze von 2 KB geht durch.
     */
    public function testFileSizeWithinLimit(): void
    {
        $result = new ValidationResult();
        (new FileSizeValidator())->validate(
            $this->file('a.pdf', str_repeat('x', 1024)),
            $this->config(maxFileSizeKb: 2),
            $result,
        );

        self::assertTrue($result->isValid());
    }

    /**
     * 3000 Byte liegen über 2 KB (2048 Byte) und werden abgewiesen. Ohne Grenze wären Platte und
     * Speicherlimit von PHP ungeschützt.
     */
    public function testFileSizeExceeded(): void
    {
        $result = new ValidationResult();
        (new FileSizeValidator())->validate(
            $this->file('a.pdf', str_repeat('x', 3000)),
            $this->config(maxFileSizeKb: 2),
            $result,
        );

        self::assertSame(ValidationCode::FILE_SIZE_EXCEEDED, $result->firstCode());
    }

    /**
     * Eine leere Datei meldet `EMPTY_UPLOAD` statt verwirrender Folgefehler zu Größe oder Typ.
     */
    public function testEmptyFileRejected(): void
    {
        $result = new ValidationResult();
        (new FileSizeValidator())->validate(
            $this->file('a.pdf', ''),
            $this->config(),
            $result,
        );

        self::assertSame(ValidationCode::EMPTY_UPLOAD, $result->firstCode());
    }

    /**
     * Gezählt wird die Summe nach dem Upload: 1 KB vorhanden plus 1 KB neu bleibt unter 3 KB.
     */
    public function testTotalSizeAccepted(): void
    {
        $result = new ValidationResult();
        (new TotalSizeValidator())->validate(
            file: $this->file('a.pdf', str_repeat('x', 1024)),
            alreadyUploadedBytes: 1024,
            config: $this->config(maxTotalSizeKb: 3),
            result: $result,
        );

        self::assertTrue($result->isValid());
    }

    /**
     * 2 KB vorhanden plus 2 KB neu übersteigen 3 KB, obwohl jede Datei für sich unter der Grenze
     * liegt. So lässt sich die Grenze nicht mit vielen kleinen Uploads umgehen.
     */
    public function testTotalSizeExceeded(): void
    {
        $result = new ValidationResult();
        (new TotalSizeValidator())->validate(
            file: $this->file('a.pdf', str_repeat('x', 2048)),
            alreadyUploadedBytes: 2048,
            config: $this->config(maxTotalSizeKb: 3),
            result: $result,
        );

        self::assertSame(ValidationCode::TOTAL_SIZE_EXCEEDED, $result->firstCode());
    }

    /**
     * Zwei vorhandene Dateien bei einer Grenze von fünf lassen Platz.
     */
    public function testFileCountWithinLimit(): void
    {
        $result = new ValidationResult();
        (new FileCountValidator())->validate(
            alreadyUploadedCount: 2,
            config: $this->config(maxFiles: 5),
            result: $result,
        );

        self::assertTrue($result->isValid());
    }

    /**
     * Grenzfall: Bei fünf vorhandenen Dateien und einer Grenze von fünf wäre der neue Upload der
     * sechste. Der Vergleich muss deshalb `>=` sein, nicht `>`.
     */
    public function testFileCountExceeded(): void
    {
        $result = new ValidationResult();
        (new FileCountValidator())->validate(
            alreadyUploadedCount: 5,
            config: $this->config(maxFiles: 5),
            result: $result,
        );

        self::assertSame(ValidationCode::FILE_COUNT_EXCEEDED, $result->firstCode());
    }

    private function file(string $name, string $contents): UploadedFile
    {
        $path = $this->tempDir . '/test-' . (++$this->fileCounter) . '-' . $name;
        file_put_contents($path, $contents);
        $this->createdFiles[] = $path;

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
