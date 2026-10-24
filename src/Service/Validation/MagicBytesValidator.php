<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Vergleicht die ersten Bytes der Datei mit der Signatur, die zur Endung gehört. So fällt eine
 * PHP-Datei mit `.pdf` oder ein umbenanntes Programm mit `.png` auf.
 *
 * Formate ohne feste Signatur (TXT, CSV, CAD-Formate als Text) lässt der Prüfer durch; für sie
 * gibt es keinen Eintrag in {@see SIGNATURES}.
 */
final class MagicBytesValidator
{
    /**
     * @var array<string, list<string>> Signaturbytes je Endung, hexadezimal geschrieben
     */
    private const SIGNATURES = [
        'pdf' => ['25504446'], // %PDF
        'jpg' => ['ffd8ff'],
        'jpeg' => ['ffd8ff'],
        'png' => ['89504e470d0a1a0a'],
        'gif' => ['474946383761', '474946383961'],
        'webp' => ['52494646'], // RIFF; die Kennung WEBP an Byte 8 prüft isWebp()
        'bmp' => ['424d'],
        'tif' => ['49492a00', '4d4d002a'],
        'tiff' => ['49492a00', '4d4d002a'],
        'zip' => ['504b0304', '504b0506', '504b0708'],
        'docx' => ['504b0304'],
        'xlsx' => ['504b0304'],
        'pptx' => ['504b0304'],
        'doc' => ['d0cf11e0a1b11ae1'],
        'xls' => ['d0cf11e0a1b11ae1'],
        'ppt' => ['d0cf11e0a1b11ae1'],
    ];

    /**
     * HEIC und HEIF, das Fotoformat des iPhones, beginnen mit vier Bytes Boxlänge, die je Datei
     * schwanken. Danach folgt `ftyp` ab Byte 4 und eine dieser Markenkennungen ab Byte 8. Ein fester
     * Anfang passt deshalb nicht; die Prüfung läuft über {@see isIsoBaseMediaContainer()}.
     *
     * @var list<string>
     */
    private const HEIC_BRANDS = ['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs', 'mif1', 'msf1'];

    public function validate(UploadedFile $file, ValidationResult $result): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === '') {
            return;
        }

        if ($extension === 'heic' || $extension === 'heif') {
            if (!$this->isIsoBaseMediaContainer($file)) {
                $result->fail(ValidationCode::MAGIC_BYTES_MISMATCH, ['extension' => $extension]);
            }

            return;
        }

        if (!isset(self::SIGNATURES[$extension])) {
            return;
        }

        // 16 Bytes decken die längste Signatur (8 Bytes) und die WEBP-Kennung an Byte 8 bis 11 ab.
        $head = $this->readHead($file, 16);
        if ($head === null) {
            $result->fail(ValidationCode::MAGIC_BYTES_MISMATCH, ['extension' => $extension]);

            return;
        }

        $hex = bin2hex($head);
        foreach (self::SIGNATURES[$extension] as $signature) {
            if (str_starts_with($hex, $signature)) {
                if ($extension === 'webp' && !$this->isWebp($head)) {
                    continue;
                }

                return;
            }
        }

        $result->fail(ValidationCode::MAGIC_BYTES_MISMATCH, ['extension' => $extension]);
    }

    private function isWebp(string $head): bool
    {
        // RIFF, vier Bytes Länge, dann `WEBP` an Byte 8 bis 11.
        return \strlen($head) >= 12 && substr($head, 8, 4) === 'WEBP';
    }

    /**
     * Erkennt das ISO Base Media File Format an `ftyp` in Byte 4 bis 7. Zugelassen sind nur die
     * Markenkennungen aus {@see HEIC_BRANDS} in Byte 8 bis 11, MP4-Dateien fallen also durch.
     */
    private function isIsoBaseMediaContainer(UploadedFile $file): bool
    {
        $head = $this->readHead($file, 12);
        if ($head === null || \strlen($head) < 12) {
            return false;
        }

        if (substr($head, 4, 4) !== 'ftyp') {
            return false;
        }

        $brand = strtolower(substr($head, 8, 4));

        return \in_array($brand, self::HEIC_BRANDS, true);
    }

    /**
     * @param positive-int $bytes
     */
    private function readHead(UploadedFile $file, int $bytes): ?string
    {
        $path = $file->getRealPath();
        if ($path === false || !is_readable($path)) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, $bytes);
        fclose($handle);

        return $head !== '' ? $head : null;
    }
}
