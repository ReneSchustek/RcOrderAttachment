<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Prüft die ersten Bytes der Datei gegen die bekannten Signatur-Patterns.
 * Schützt vor falsch deklarierten Dateien (z. B. .pdf-Endung um eine PHP-Datei
 * herum, oder umbenannte .exe als .png).
 *
 * Für Formate ohne stabile Signatur (TXT, CSV, CAD-Reintext) wird nicht geprüft.
 */
final class MagicBytesValidator
{
    /**
     * @var array<string, list<string>> hex-encoded Magic-Bytes je Endung
     */
    private const SIGNATURES = [
        'pdf' => ['25504446'], // %PDF
        'jpg' => ['ffd8ff'],
        'jpeg' => ['ffd8ff'],
        'png' => ['89504e470d0a1a0a'],
        'gif' => ['474946383761', '474946383961'],
        'webp' => ['52494646'], // RIFF, weitere Prüfung über WEBP-Kennung im Datenstrom
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
     * HEIC/HEIF (iPhone-Foto-Format): die Box-Struktur beginnt mit einer
     * variablen Größen-Box, gefolgt von `ftyp` ab Byte 4 und einer dieser
     * Brand-Kennungen ab Byte 8. Wir prüfen also nicht mit festen Hex-Prefixes,
     * sondern explizit über {@see isIsoBaseMediaContainer()}.
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

        // HEIC/HEIF werden separat geprüft (variable Box-Struktur, keine festen
        // Hex-Prefixes möglich).
        if ($extension === 'heic' || $extension === 'heif') {
            if (!$this->isIsoBaseMediaContainer($file)) {
                $result->fail(ValidationCode::MAGIC_BYTES_MISMATCH, ['extension' => $extension]);
            }

            return;
        }

        if (!isset(self::SIGNATURES[$extension])) {
            return;
        }

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
        // RIFF????WEBP — Bytes 8–11 müssen 'WEBP' sein.
        return \strlen($head) >= 12 && substr($head, 8, 4) === 'WEBP';
    }

    /**
     * Prüft ISO Base Media File Format (HEIC, HEIF, MP4-Familie).
     * Bytes 4–7 müssen `ftyp` sein, Bytes 8–11 ist die Brand-Kennung.
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
