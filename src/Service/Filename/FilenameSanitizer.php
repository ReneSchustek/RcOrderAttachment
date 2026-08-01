<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Filename;

use Ruhrcoder\RcOrderAttachment\Service\Exception\InvalidAttachmentPayloadException;

/**
 * Bereinigt vom Client gelieferte Filenames.
 *
 * Eine einzige Quelle der Wahrheit für die Sanitization — vom UploadService
 * vor dem Schreiben in den PendingUpload und vom OrderAttachmentManager vor
 * dem Persistieren in der DAL.
 *
 * Entfernt werden:
 * - Pfad-Komponenten (`/`, `\`) durch `basename`
 * - ASCII-Steuerzeichen (`\x00`–`\x1F`, `\x7F`) inkl. CR, LF, Null-Byte, Tab
 * - Unicode-Bidi-Marker (U+202A–U+202E, U+2066–U+2069) — schützen vor
 *   RTL-Override-Phishing wie `cute‮gpj.exe` (Anzeige als `cute.exe.jpg`)
 * - Führende/abschließende Punkte und Whitespace
 * - Längen-Limit 255 Zeichen via `mb_substr`
 */
final class FilenameSanitizer
{
    public const MAX_LENGTH = 255;

    /**
     * Pattern matched ASCII-Control-Chars (`\x00`–`\x1F`, `\x7F`) und die
     * Unicode-Bidi-Marker als UTF-8-Bytefolgen.
     *
     * - U+202A–U+202E → `0xE2 0x80 0xAA`–`0xE2 0x80 0xAE`
     * - U+2066–U+2069 → `0xE2 0x81 0xA6`–`0xE2 0x81 0xA9`
     */
    private const DANGEROUS_CHAR_PATTERN = '/[\x00-\x1F\x7F]|\xE2\x80[\xAA-\xAE]|\xE2\x81[\xA6-\xA9]/';

    public function sanitize(string $rawName): string
    {
        $trimmed = trim($rawName);
        if ($trimmed === '') {
            throw new InvalidAttachmentPayloadException('originalFileName', 'darf nicht leer sein');
        }

        // Windows-Backslashes auf Forward-Slashes normalisieren, damit basename()
        // OS-unabhängig den letzten Pfadbestandteil extrahiert. Auf Linux behandelt
        // basename() Backslashes sonst als reguläre Zeichen.
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
