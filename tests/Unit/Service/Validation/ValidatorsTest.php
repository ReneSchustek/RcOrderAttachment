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
 * Unit-Tests für die Einzel-Validatoren der Upload-Pipeline. Deckt Whitelist,
 * Blacklist, MIME, Magic-Bytes, Größen- und Anzahl-Limits ab. Die Orchestrierung
 * wird separat in {@see UploadValidatorTest} geprüft.
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
        // Prozess-ID im Pfad: Der Zähler unten startet je Lauf wieder bei 0, ein gemeinsames
        // Verzeichnis würde bei parallelen Läufen (CI-Matrix, --processes) also kollidieren.
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

    // --- ExtensionValidator -------------------------------------------------

    /**
     * Was: Whitelist `['pdf']`, Customer lädt `drawing.pdf` hoch.
     * Warum: Standardfall — pdf gehört zur Default-Whitelist, muss durchgehen.
     * Erwartet: `isValid() = true`.
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
     * Was: Whitelist `['pdf']`, Customer lädt `.exe` hoch.
     * Warum: Schutz vor offensichtlich nicht erwünschten Dateitypen.
     * Erwartet: Reject mit `EXTENSION_NOT_ALLOWED`.
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
     * Was: Datei ohne Endung (`noext`).
     * Warum: Browser/CLI können Dateien ohne Extension hochladen — ohne klare
     *        Endung können wir MIME nicht zuverlässig matchen. Reject ist sicherer.
     * Erwartet: `EXTENSION_NOT_ALLOWED`.
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

    // --- DangerousContentValidator ------------------------------------------

    /**
     * Was: Verbotene Endungen (php, exe, bat, …) auf eine harmlose Datei.
     * Warum: Hart kodierte Blacklist muss greifen, auch wenn Admin eine
     *        gefährliche Endung in die Whitelist eingetragen hat — defense-in-depth.
     * Erwartet: `EXTENSION_BLACKLISTED` für jede einzelne Endung.
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
     * Was: `.pdf`-Endung, aber Inhalt enthält `<?php`.
     * Warum: Polyglot-File-Schutz — Angreifer könnte versuchen, PHP-Code in
     *        eine als PDF deklarierte Datei zu schmuggeln. Script-Signatur-Check
     *        scannt die ersten 2 KB.
     * Erwartet: `DANGEROUS_CONTENT_DETECTED`.
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
     * Was: PDF-Header gefolgt von `<SCRIPT>`-Tag.
     * Warum: XSS-Schutz in PDF-Viewern, die HTML interpretieren könnten.
     *        PDF-Header wird vorangestellt, damit der vorher laufende
     *        MIME-Check nicht greift (sonst wäre der MIME-Pfad „text/html" der
     *        Reject-Grund — wir wollen aber den Signature-Pfad testen).
     * Erwartet: `DANGEROUS_CONTENT_DETECTED`.
     */
    public function testDangerousContentDetectsScriptTag(): void
    {
        // PDF-Header voranstellen, damit finfo nicht text/html erkennt und
        // der MIME-Pfad nicht vorher zuschlägt — Ziel ist die Signature-Erkennung.
        $payload = "%PDF-1.4\n%fake\n<SCRIPT>alert(1)</SCRIPT>";

        $result = new ValidationResult();
        (new DangerousContentValidator())->validate(
            $this->file('innocent.pdf', $payload),
            $result,
        );

        self::assertSame(ValidationCode::DANGEROUS_CONTENT_DETECTED, $result->firstCode());
    }

    /**
     * Was: PDF-Endung, Inhalt beginnt mit `#!/bin/bash`.
     * Warum: Shebang-Signatur — Schutz vor Shell-Skripten, die als andere
     *        Dateitypen getarnt sind.
     * Erwartet: `DANGEROUS_CONTENT_DETECTED`.
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

    // --- MagicBytesValidator ------------------------------------------------

    /**
     * Was: PDF mit korrektem `%PDF-1.7`-Header.
     * Warum: Happy-Path für die Magic-Bytes-Validierung — echte PDFs müssen
     *        durchgehen.
     * Erwartet: `isValid() = true`.
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
     * Was: `.pdf`-Endung, aber Inhalt ist Klartext ohne `%PDF`-Header.
     * Warum: Schutz vor Extension-Spoofing. Endung allein darf nicht reichen,
     *        die Magic-Bytes müssen den Typ bestätigen.
     * Erwartet: `MAGIC_BYTES_MISMATCH`.
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
     * Was: PNG mit korrektem 8-Byte-Header.
     * Warum: PNG ist häufigstes Customer-Foto-Format. Header-Match muss funktionieren.
     * Erwartet: `isValid() = true`.
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
     * Was: Endung `.dxf` (CAD-Format) ohne registrierte Magic-Bytes.
     * Warum: Für Endungen ohne stabile Signatur (CAD-Reintext, TXT, CSV)
     *        darf der Validator nicht ablehnen — sonst wären zulässige
     *        Whitelist-Endungen unbenutzbar.
     * Erwartet: `isValid() = true` (Validator springt früh raus).
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

    // --- FileSizeValidator --------------------------------------------------

    /**
     * Was: Datei 1 KB, Limit 2 KB.
     * Warum: Innerhalb des Limits darf nichts blockieren.
     * Erwartet: `isValid() = true`.
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
     * Was: Datei 3 KB, Limit 2 KB.
     * Warum: Über dem Limit muss zuverlässig abgelehnt werden — sonst sind
     *        Disk-Quota und PHP-Memory-Limit nicht geschützt.
     * Erwartet: `FILE_SIZE_EXCEEDED`.
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
     * Was: Datei mit leerem Inhalt (0 Bytes).
     * Warum: Customer könnte versehentlich auf „Upload" klicken ohne Datei.
     *        Wir wollen explizit `EMPTY_UPLOAD` melden statt verwirrenden
     *        Size-/MIME-Folgefehlern.
     * Erwartet: `EMPTY_UPLOAD`.
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

    // --- TotalSizeValidator -------------------------------------------------

    /**
     * Was: Bereits 1 KB im Store, neue Datei 1 KB, Limit 3 KB.
     * Warum: Projektion (1+1=2) liegt unter Limit (3) — Upload muss durchgehen.
     * Erwartet: `isValid() = true`.
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
     * Was: Bereits 2 KB im Store, neue Datei 2 KB, Limit 3 KB.
     * Warum: Projektion (2+2=4) übersteigt Limit (3) — Upload muss
     *        abgelehnt werden. Schutz vor „Dutzend kleiner Uploads"-DoS.
     * Erwartet: `TOTAL_SIZE_EXCEEDED`.
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

    // --- FileCountValidator -------------------------------------------------

    /**
     * Was: Bereits 2 Dateien im Store, Limit 5.
     * Warum: Innerhalb der Anzahl-Quote darf nichts blockieren.
     * Erwartet: `isValid() = true`.
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
     * Was: Bereits 5 Dateien im Store, Limit 5.
     * Warum: `>=`-Vergleich — der neue Upload würde Slot 6 belegen, also voll.
     *        Schutz vor unbegrenzter Datei-Anzahl pro Bestellung.
     * Erwartet: `FILE_COUNT_EXCEEDED`.
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

    // --- Helpers ------------------------------------------------------------

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
