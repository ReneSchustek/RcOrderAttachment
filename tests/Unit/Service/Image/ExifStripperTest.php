<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Image;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Service\Image\ExifStripper;

/**
 * Unit-Tests für den EXIF-Stripper. Sichert das DSGVO-relevante Verhalten:
 * Re-Encode mit GD entfernt EXIF/IPTC/XMP, unbekannte Endungen werden
 * fail-safe durchgelassen statt zu brechen.
 */
final class ExifStripperTest extends TestCase
{
    private string $tempDir;

    private int $counter = 0;

    /**
     * @var list<string>
     */
    private array $created = [];

    private ExifStripper $stripper;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/rc-order-attachment-exif-tests';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0o755, true);
        }
        $this->counter = 0;
        $this->stripper = new ExifStripper(new NullLogger());
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->created = [];
    }

    /**
     * Was: Strip-Aufruf für `.pdf` (nicht-Bildtyp).
     * Warum: Stripper soll nicht in PDF-Strukturen reinwühlen — PDFs haben
     *        eigene Metadaten, die andere Werkzeuge (`qpdf`) entfernen.
     * Erwartet: `false` (kein Strip durchgeführt), keine Datei-Mutation.
     */
    public function testReturnsFalseForUnsupportedExtension(): void
    {
        $path = $this->writeTempFile('test.pdf', '%PDF-1.5');

        self::assertFalse($this->stripper->stripInPlace($path, 'pdf'));
    }

    /**
     * Was: Strip-Aufruf für `.heic` (iPhone-Foto-Format, GD-inkompatibel).
     * Warum: GD kann HEIC nicht — wir loggen einen Hinweis und reichen das
     *        Bild unverändert weiter, statt zu crashen. README empfiehlt
     *        Imagick-Setup bei DSGVO-sensiblen Setups.
     * Erwartet: `false`, kein Throw, kein Datei-Verlust.
     */
    public function testReturnsFalseForHeic(): void
    {
        // HEIC kann GD nicht — Stripper returns false (Fail-Open, kein Throw).
        $path = $this->writeTempFile('test.heic', 'binary content');

        self::assertFalse($this->stripper->stripInPlace($path, 'heic'));
    }

    /**
     * Was: Strip-Aufruf für einen nicht existierenden Pfad.
     * Warum: Race-Condition-Schutz — die Datei könnte zwischen Validierung und
     *        Strip vom OS gelöscht worden sein. Stripper darf nicht crashen,
     *        sondern muss fail-safe `false` liefern.
     * Erwartet: `false` ohne Throw.
     */
    public function testReturnsFalseForNonExistentPath(): void
    {
        self::assertFalse($this->stripper->stripInPlace($this->tempDir . '/does-not-exist.jpg', 'jpg'));
    }

    /**
     * Was: Eingabedatei mit `.jpg`-Endung, aber kaputtem Inhalt.
     * Warum: `imagecreatefromjpeg` würde `false` zurückgeben und (mit @-Op
     *        unterdrückt) einen Warning werfen. Der Stripper muss das fangen
     *        und fail-safe weitermachen.
     * Erwartet: `false`, keine Datei-Mutation.
     */
    public function testReturnsFalseForCorruptJpeg(): void
    {
        $path = $this->writeTempFile('corrupt.jpg', 'not a real jpeg');

        self::assertFalse($this->stripper->stripInPlace($path, 'jpg'));
    }

    /**
     * Was: Re-Encode eines gültigen, mit GD erzeugten JPEGs.
     * Warum: Happy-Path — der DSGVO-relevante Code-Pfad. EXIF/GPS-Daten würden
     *        durch das Re-Encode automatisch wegfallen.
     * Erwartet: `true` und Datei nicht leer.
     */
    public function testStripsValidJpeg(): void
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagejpeg')) {
            self::markTestSkipped('GD-Extension nicht verfügbar');
        }

        $path = $this->writeJpegWithExif();

        $result = $this->stripper->stripInPlace($path, 'jpg');

        self::assertTrue($result);
        self::assertGreaterThan(0, filesize($path), 'Datei darf nach Strip nicht leer sein');
    }

    /**
     * Was: Re-Encode eines PNGs mit Alpha-Kanal.
     * Warum: PNG-Re-Encode darf den Alpha-Kanal NICHT wegwerfen — sonst hätten
     *        Produktfotos mit transparentem Hintergrund nach dem Strip einen
     *        schwarzen Hintergrund.
     * Erwartet: `true`, Datei nicht leer.
     */
    public function testStripsValidPngPreservingAlpha(): void
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagepng')) {
            self::markTestSkipped('GD-Extension nicht verfügbar');
        }

        $path = $this->writePngWithAlpha();

        $result = $this->stripper->stripInPlace($path, 'png');

        self::assertTrue($result);
        self::assertGreaterThan(0, filesize($path));
    }

    /**
     * Was: Strip mit großgeschriebener Extension (`JPG`).
     * Warum: Browser senden Extensions in beliebiger Case. Case-sensitive
     *        Vergleich würde `IMG.JPG` durchlassen (nicht gestrippt) und
     *        DSGVO-Pflicht verletzen.
     * Erwartet: `true` — Extension wird intern auf lowercase normalisiert.
     */
    public function testCaseInsensitiveExtension(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD-Extension nicht verfügbar');
        }

        $path = $this->writeJpegWithExif();

        // Großgeschriebene Extension wird ebenso normalisiert
        self::assertTrue($this->stripper->stripInPlace($path, 'JPG'));
    }

    private function writeTempFile(string $name, string $content): string
    {
        $path = $this->tempDir . '/test-' . (++$this->counter) . '-' . $name;
        file_put_contents($path, $content);
        $this->created[] = $path;

        return $path;
    }

    private function writeJpegWithExif(): string
    {
        // `imagedestroy()` ist seit PHP 8.5 deprecated und seit 8.0 wirkungslos —
        // GC räumt das GdImage-Objekt nach dem Funktionsende selbständig auf.
        $img = imagecreatetruecolor(10, 10);
        $farbe = imagecolorallocate($img, 255, 0, 0);
        self::assertNotFalse($farbe, 'Farbe liess sich nicht belegen.');
        imagefill($img, 0, 0, $farbe);

        $path = $this->tempDir . '/test-' . (++$this->counter) . '.jpg';
        imagejpeg($img, $path, 90);
        $this->created[] = $path;

        return $path;
    }

    private function writePngWithAlpha(): string
    {
        $img = imagecreatetruecolor(10, 10);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        self::assertNotFalse($transparent, 'Transparenzfarbe liess sich nicht belegen.');
        imagefill($img, 0, 0, $transparent);

        $path = $this->tempDir . '/test-' . (++$this->counter) . '.png';
        imagepng($img, $path);
        $this->created[] = $path;

        return $path;
    }
}
