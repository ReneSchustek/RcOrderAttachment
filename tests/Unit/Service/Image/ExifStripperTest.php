<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Image;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Service\Image\ExifStripper;

/**
 * Prüft das Entfernen von Metadaten aus Kundenfotos, das aus Datenschutzgründen GPS-Position
 * und Kameradaten tilgt: Das Neukodieren mit GD gelingt, und alles, was GD nicht lesen kann,
 * wird unverändert durchgereicht, statt den Upload abzubrechen.
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
     * PDFs tragen eigene Metadaten, die nur eigene Werkzeuge wie `qpdf` sauber entfernen. Der
     * Stripper lässt sie unangetastet und meldet `false`.
     */
    public function testReturnsFalseForUnsupportedExtension(): void
    {
        $path = $this->writeTempFile('test.pdf', '%PDF-1.5');

        self::assertFalse($this->stripper->stripInPlace($path, 'pdf'));
    }

    /**
     * GD kann das iPhone-Format HEIC nicht lesen. Der Stripper protokolliert das und reicht die
     * Datei unverändert weiter, statt den Upload abzubrechen.
     */
    public function testReturnsFalseForHeic(): void
    {
        $path = $this->writeTempFile('test.heic', 'binary content');

        self::assertFalse($this->stripper->stripInPlace($path, 'heic'));
    }

    /**
     * Zwischen Prüfung und Bereinigung kann die temporäre Datei schon verschwunden sein. Der
     * Stripper meldet dann `false`, statt zu werfen.
     */
    public function testReturnsFalseForNonExistentPath(): void
    {
        self::assertFalse($this->stripper->stripInPlace($this->tempDir . '/does-not-exist.jpg', 'jpg'));
    }

    /**
     * Bei kaputtem Inhalt liefert `imagecreatefromjpeg()` `false` samt Warnung. Der Stripper
     * fängt beides ab und meldet `false`.
     */
    public function testReturnsFalseForCorruptJpeg(): void
    {
        $path = $this->writeTempFile('corrupt.jpg', 'not a real jpeg');

        self::assertFalse($this->stripper->stripInPlace($path, 'jpg'));
    }

    /**
     * Der Zweck der Klasse: Ein Foto mit EXIF-Block hat danach keinen mehr. GD schreibt kein EXIF,
     * deshalb setzt der Test den Block als APP1-Segment selbst ins JPEG.
     */
    public function testRemovesTheExifBlock(): void
    {
        $this->requireGdAndExif();
        $path = $this->writeJpeg(10, 10);
        $this->injectExifOrientation($path, 1);
        self::assertSame(1, $this->exifOrientation($path), 'Der Test muss mit einem EXIF-Block beginnen.');

        self::assertTrue($this->stripper->stripInPlace($path, 'jpg'));

        self::assertNull($this->exifOrientation($path), 'Nach dem Entfernen darf kein EXIF-Block mehr da sein.');
    }

    /**
     * Ein Hochkantfoto steht oft nur über die EXIF-Ausrichtung aufrecht (6 = im Uhrzeigersinn drehen).
     * Fällt das Kennzeichen weg, muss das Bild selbst gedreht sein, sonst sieht der Betrieb es quer.
     * Das Testbild ist 20×10, links rot, rechts blau; aufgerichtet ist es 10×20, oben rot.
     */
    public function testAPortraitPhotoStaysUpright(): void
    {
        $this->requireGdAndExif();
        $path = $this->writeJpeg(20, 10, splitColours: true);
        $this->injectExifOrientation($path, 6);

        self::assertTrue($this->stripper->stripInPlace($path, 'jpg'));

        $image = imagecreatefromjpeg($path);
        self::assertNotFalse($image);
        self::assertSame([10, 20], [imagesx($image), imagesy($image)]);
        self::assertGreaterThan(150, (imagecolorat($image, 5, 2) >> 16) & 0xFF, 'Oben muss die rote Hälfte stehen.');
        self::assertGreaterThan(150, imagecolorat($image, 5, 17) & 0xFF, 'Unten muss die blaue Hälfte stehen.');
    }

    /**
     * Wächter der Geschwindigkeit: ein Foto mit 6 Megapixeln, wie es ältere Smartphones liefern.
     * Gemessen sind für 12 Megapixel rund 140 ms (`benchmarks/ExifStripperBench.php`); die Grenze von
     * zwei Sekunden ist großzügig gegen Schwankungen und fängt doch einen Umbau, der jedes Foto
     * mehrfach dekodiert.
     */
    public function testAPhoneSizedPhotoIsStrippedQuickly(): void
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagejpeg')) {
            self::markTestSkipped('GD-Extension nicht verfügbar');
        }

        $image = imagecreatetruecolor(3000, 2000);
        imagefilledrectangle($image, 0, 0, 1499, 1999, (int) imagecolorallocate($image, 200, 120, 40));
        $path = $this->tempDir . '/rc-exif-speed-' . bin2hex(random_bytes(4)) . '.jpg';
        imagejpeg($image, $path, 90);
        $this->created[] = $path;

        $start = hrtime(true);
        $stripped = $this->stripper->stripInPlace($path, 'jpg');
        $milliseconds = (hrtime(true) - $start) / 1_000_000;

        self::assertTrue($stripped);
        self::assertLessThan(2000, $milliseconds);
    }

    /**
     * Ein PNG mit Transparenz bleibt transparent. Ginge der Alphakanal beim Neuschreiben verloren,
     * hätten freigestellte Fotos danach einen schwarzen Hintergrund.
     */
    public function testAPngKeepsItsTransparency(): void
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('imagepng')) {
            self::markTestSkipped('GD-Extension nicht verfügbar');
        }

        $path = $this->writePngWithAlpha();

        self::assertTrue($this->stripper->stripInPlace($path, 'png'));

        $image = imagecreatefrompng($path);
        self::assertNotFalse($image);
        self::assertSame(127, (imagecolorat($image, 5, 5) >> 24) & 0x7F, 'Der Bildpunkt muss ganz durchsichtig bleiben.');
    }

    /**
     * Endungen kommen in beliebiger Schreibweise. Ein Vergleich mit Groß- und Kleinschreibung
     * ließe `IMG.JPG` samt Standortdaten ungefiltert durch.
     */
    public function testCaseInsensitiveExtension(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD-Extension nicht verfügbar');
        }

        $path = $this->writeJpeg(10, 10);

        self::assertTrue($this->stripper->stripInPlace($path, 'JPG'));
    }

    private function writeTempFile(string $name, string $content): string
    {
        $path = $this->tempDir . '/test-' . (++$this->counter) . '-' . $name;
        file_put_contents($path, $content);
        $this->created[] = $path;

        return $path;
    }

    private function requireGdAndExif(): void
    {
        if (!\function_exists('imagecreatetruecolor') || !\function_exists('exif_read_data')) {
            self::markTestSkipped('GD- oder exif-Erweiterung nicht verfügbar');
        }
    }

    /**
     * Ein JPEG ohne Metadaten, einfarbig rot oder links rot und rechts blau.
     *
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function writeJpeg(int $width, int $height, bool $splitColours = false): string
    {
        // Kein `imagedestroy()`: Unter PHP 8 bewirkt es nichts, und PHP 8.5 markiert es als veraltet.
        // Das `GdImage`-Objekt räumt die Speicherbereinigung am Ende der Funktion selbst ab.
        $img = imagecreatetruecolor($width, $height);
        $red = imagecolorallocate($img, 255, 0, 0);
        $blue = imagecolorallocate($img, 0, 0, 255);
        self::assertNotFalse($red, 'Farbe ließ sich nicht belegen.');
        self::assertNotFalse($blue, 'Farbe ließ sich nicht belegen.');
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $red);
        if ($splitColours) {
            imagefilledrectangle($img, intdiv($width, 2), 0, $width - 1, $height - 1, $blue);
        }

        $path = $this->tempDir . '/test-' . (++$this->counter) . '.jpg';
        imagejpeg($img, $path, 90);
        $this->created[] = $path;

        return $path;
    }

    /**
     * Setzt hinter den Dateianfang ein APP1-Segment mit einem EXIF-Block, der nur die Ausrichtung
     * trägt: TIFF-Kopf in Little Endian, ein Verzeichnis mit einem Eintrag (0x0112, SHORT).
     */
    private function injectExifOrientation(string $path, int $orientation): void
    {
        $tiff = 'II' . pack('vV', 0x2A, 8) . pack('v', 1) . pack('vvVvv', 0x0112, 3, 1, $orientation, 0) . pack('V', 0);
        $payload = "Exif\0\0" . $tiff;
        $segment = "\xFF\xE1" . pack('n', \strlen($payload) + 2) . $payload;

        $jpeg = (string) file_get_contents($path);
        file_put_contents($path, substr($jpeg, 0, 2) . $segment . substr($jpeg, 2));
    }

    private function exifOrientation(string $path): ?int
    {
        $exif = @exif_read_data($path);

        return \is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : null;
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
