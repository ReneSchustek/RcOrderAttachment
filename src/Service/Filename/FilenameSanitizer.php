<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Filename;

use Ruhrcoder\RcOrderAttachment\Service\Exception\InvalidAttachmentPayloadException;

/**
 * Bereinigt die Dateinamen, die der Browser mitschickt. Upload-Dienst und OrderAttachmentManager
 * nutzen beide diese Klasse, damit ein Name in der Sitzung und in der Datenbank gleich aussieht.
 *
 * Pfadanteile mit `/` oder `\` schneidet `basename` ab. Steuerzeichen (`\x00` bis `\x1F`,
 * `\x7F`) und die Unicode-Richtungszeichen U+202A bis U+202E und U+2066 bis U+2069 werden zu `_`.
 * Die Richtungszeichen sind gefährlich, weil `cute<U+202E>gpj.exe` als `cuteexe.jpg` angezeigt
 * wird und so eine ausführbare Datei als Bild tarnt. Zuletzt fallen Punkte und Leerraum an den
 * Rändern weg, und der Name wird auf {@see MAX_LENGTH} Zeichen gekürzt.
 */
final class FilenameSanitizer
{
    // Entspricht der Spalte `original_file_name VARCHAR(255)`.
    public const MAX_LENGTH = 255;

    /**
     * Trifft ASCII-Steuerzeichen und die Richtungszeichen als UTF-8-Bytefolgen: U+202A bis U+202E
     * sind `E2 80 AA` bis `E2 80 AE`, U+2066 bis U+2069 sind `E2 81 A6` bis `E2 81 A9`.
     */
    private const DANGEROUS_CHAR_PATTERN = '/[\x00-\x1F\x7F]|\xE2\x80[\xAA-\xAE]|\xE2\x81[\xA6-\xA9]/';

    public function sanitize(string $rawName): string
    {
        $trimmed = trim($rawName);
        if ($trimmed === '') {
            throw new InvalidAttachmentPayloadException('originalFileName', 'darf nicht leer sein');
        }

        // Unter Linux behandelt basename() einen Backslash als gewöhnliches Zeichen. Erst nach dem
        // Tausch gegen `/` fällt auch ein Windows-Pfad wie `C:\Users\a.pdf` auf den Dateinamen.
        $normalized = str_replace('\\', '/', $trimmed);
        $base = basename($normalized);

        $clean = preg_replace(self::DANGEROUS_CHAR_PATTERN, '_', $base) ?? $base;
        $clean = trim($clean, ". \t\r\n\0");

        if ($clean === '') {
            throw new InvalidAttachmentPayloadException('originalFileName', 'nach Bereinigung leer');
        }

        if (mb_strlen($clean) > self::MAX_LENGTH) {
            $clean = mb_substr($clean, 0, self::MAX_LENGTH);
        }

        return $clean;
    }
}
