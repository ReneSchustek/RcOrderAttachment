<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Filename;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Exception\InvalidAttachmentPayloadException;
use Ruhrcoder\RcOrderAttachment\Service\Filename\FilenameSanitizer;

/**
 * Unit-Tests für die Filename-Bereinigung — CRLF, Steuerzeichen, Unicode-Bidi,
 * Pfad-Komponenten, Längen-Limit. Schützt die zentrale Sanitization-Stelle vor
 * Regressionen (siehe Security-Begründung im Sanitizer selbst).
 */
final class FilenameSanitizerTest extends TestCase
{
    private FilenameSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new FilenameSanitizer();
    }

    /**
     * Was: Normaler Filename ohne Sonderzeichen.
     * Warum: Happy-Path — eine harmlose Eingabe darf nicht versehentlich
     *        modifiziert werden, sonst geht der ursprüngliche Name verloren.
     * Erwartet: Identisches Resultat ohne Änderungen.
     */
    public function testHarmlessFilenamePassesUnchanged(): void
    {
        self::assertSame('drawing.pdf', $this->sanitizer->sanitize('drawing.pdf'));
    }

    /**
     * Was: Filename mit führenden/abschließenden Leerzeichen.
     * Warum: Browser-Copy-Paste fügt oft Whitespace ein — Filesystem behandelt
     *        Whitespace im Filename inkonsistent zwischen OS.
     * Erwartet: Whitespace außen wird entfernt.
     */
    public function testTrimsLeadingTrailingWhitespace(): void
    {
        self::assertSame('drawing.pdf', $this->sanitizer->sanitize("  drawing.pdf  "));
    }

    /**
     * Was: Filenames mit Path-Traversal-Komponenten (`../`, Windows `\`).
     * Warum: Direkter Schutz gegen Path-Traversal — `basename()` darf den
     *        ursprünglichen Filename nur als letzten Pfadbestandteil interpretieren.
     * Erwartet: Nur der Basename überlebt; `../etc/passwd` → `passwd`.
     */
    public function testStripsPathComponents(): void
    {
        self::assertSame('passwd', $this->sanitizer->sanitize('../../../etc/passwd'));
        self::assertSame('config.ini', $this->sanitizer->sanitize('C:\\Windows\\System32\\config.ini'));
    }

    /**
     * Was: Filenames mit CR, LF, Tab, Null-Byte.
     * Warum: Schutz vor Log-Injection und Mail-Header-Injection — ein CRLF in
     *        einem Filename, der später in einen Mail-Header rutscht, kann
     *        zusätzliche Header injizieren.
     * Erwartet: Steuerzeichen werden durch `_` ersetzt.
     */
    public function testReplacesCrLfTabNull(): void
    {
        self::assertSame('report__forwarded.pdf', $this->sanitizer->sanitize("report\r\nforwarded.pdf"));
        self::assertSame('a_b.pdf', $this->sanitizer->sanitize("a\tb.pdf"));
        self::assertSame('a_b.pdf', $this->sanitizer->sanitize("a\0b.pdf"));
    }

    /**
     * Was: Filenames mit ASCII-Control-Characters (`\x01`–`\x1F`, `\x7F`).
     * Warum: Defense-in-Depth — Steuerzeichen können in PDF-Readern oder
     *        Mail-Clients unerwartete Effekte (Terminal-Codes etc.) auslösen.
     * Erwartet: Alle Control-Chars durch `_` ersetzt.
     */
    public function testStripsAsciiControlCharacters(): void
    {
        $input = "report\x01\x02\x1F\x7F.pdf";
        $output = $this->sanitizer->sanitize($input);
        self::assertSame('report____.pdf', $output);
    }

    /**
     * Was: Filename mit Unicode-Bidi-Markern (U+202A–E, U+2066–9).
     * Warum: RTL-Override-Phishing — `cute<U+202E>gpj.exe` wird im Datei-
     *        Explorer als `cute.exe.jpg` angezeigt und täuscht den Nutzer.
     *        Pflicht aus Security-Sicht.
     * Erwartet: Alle Bidi-Marker werden durch `_` ersetzt; Sequenz der nicht-
     *           Bidi-Bytes bleibt unverändert.
     */
    #[DataProvider('provideBidiMarkers')]
    public function testStripsUnicodeBidiMarkers(string $input, string $expected): void
    {
        self::assertSame($expected, $this->sanitizer->sanitize($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideBidiMarkers(): array
    {
        return [
            'RTL-Override U+202E' => ["cute\xE2\x80\xAEgpj.exe", 'cute_gpj.exe'],
            'LRE U+202A' => ["a\xE2\x80\xAAb.pdf", 'a_b.pdf'],
            'LRO U+202D' => ["a\xE2\x80\xADb.pdf", 'a_b.pdf'],
            'PDF U+202C' => ["a\xE2\x80\xACb.pdf", 'a_b.pdf'],
            'LRI U+2066' => ["a\xE2\x81\xA6b.pdf", 'a_b.pdf'],
            'PDI U+2069' => ["a\xE2\x81\xA9b.pdf", 'a_b.pdf'],
        ];
    }

    /**
     * Was: Filenames mit führenden oder abschließenden Punkten.
     * Warum: Unix-„versteckte Dateien" (`.htaccess`) und Windows-Trailing-Dots
     *        können das Filesystem-Verhalten verändern oder Dateien unsichtbar
     *        machen.
     * Erwartet: Punkte am Anfang/Ende werden getrimmt.
     */
    public function testTrimsLeadingTrailingDots(): void
    {
        self::assertSame('pdf', $this->sanitizer->sanitize('....pdf'));
        self::assertSame('drawing.pdf', $this->sanitizer->sanitize('drawing.pdf...'));
        self::assertSame('drawing.pdf', $this->sanitizer->sanitize('...drawing.pdf...'));
    }

    /**
     * Was: 300 Zeichen langer Filename.
     * Warum: Die DB-Spalte `original_file_name` ist `VARCHAR(255)` — längere
     *        Werte würden bei einem strikten SQL-Mode ein Insert-Fehler werfen.
     *        Außerdem haben Filesystems eigene Limits.
     * Erwartet: Länge wird auf `FilenameSanitizer::MAX_LENGTH` (255) gekappt.
     */
    public function testTrimsToMaxLength(): void
    {
        $long = str_repeat('a', 300) . '.pdf';
        $result = $this->sanitizer->sanitize($long);
        self::assertSame(FilenameSanitizer::MAX_LENGTH, mb_strlen($result));
    }

    /**
     * Was: Filenames mit deutschen Umlauten und CJK-Zeichen.
     * Warum: B2C-Kundschaft lädt oft Dateien mit Unicode-Filenames hoch
     *        („Größe-Übermaß.pdf"). Diese müssen erhalten bleiben — nur die
     *        gefährlichen Bidi-/Control-Chars sollen rausfliegen.
     * Erwartet: Multibyte-Zeichen bleiben unverändert.
     */
    public function testHandlesMultibyteUnicodeCorrectly(): void
    {
        self::assertSame('Größe-Übermaß.pdf', $this->sanitizer->sanitize('Größe-Übermaß.pdf'));
        self::assertSame('文件.pdf', $this->sanitizer->sanitize('文件.pdf'));
    }

    /**
     * Was: Leerer String als Eingabe.
     * Warum: Programmierfehler oder kaputter Upload — der Sanitizer darf nicht
     *        stillschweigend einen leeren Filename zurückgeben, sonst landet
     *        ein leerer String in der DB.
     * Erwartet: `InvalidAttachmentPayloadException`.
     */
    public function testThrowsOnEmptyInput(): void
    {
        $this->expectException(InvalidAttachmentPayloadException::class);
        $this->sanitizer->sanitize('');
    }

    /**
     * Was: Eingabe nur aus Whitespace und Steuerzeichen.
     * Warum: Nach dem Trim bleibt ein leerer String — derselbe Fehler wie bei
     *        komplett leerem Input.
     * Erwartet: `InvalidAttachmentPayloadException`.
     */
    public function testThrowsOnWhitespaceOnlyInput(): void
    {
        $this->expectException(InvalidAttachmentPayloadException::class);
        $this->sanitizer->sanitize("   \t\r\n   ");
    }

    /**
     * Was: Eingabe besteht NUR aus Bidi-Markern.
     * Warum: Edge-Case — die Bidi-Marker werden durch `_` ersetzt, nicht
     *        entfernt. Resultat ist also nicht leer, wird durchgelassen.
     * Erwartet: `__` (zwei Underscores, je einer pro Marker).
     */
    public function testBidiOnlyInputReplacedWithUnderscores(): void
    {
        // Bidi-Marker werden auf `_` ersetzt (nicht gelöscht) — Resultat ist nicht leer.
        self::assertSame('__', $this->sanitizer->sanitize("\xE2\x80\xAE\xE2\x80\xAA"));
    }

    /**
     * Was: Eingabe `..` (Parent-Directory-Notation).
     * Warum: `basename('..')` liefert `..`, dann werden alle Dots getrimmt → leer.
     *        Schutz vor Path-Traversal-ähnlichen Eingaben.
     * Erwartet: `InvalidAttachmentPayloadException`.
     */
    public function testDoubleDotIsNotConsideredAFile(): void
    {
        // basename('..') → '..', trim auf Dots → '' → Exception.
        $this->expectException(InvalidAttachmentPayloadException::class);
        $this->sanitizer->sanitize('..');
    }

    /**
     * Was: Eingabe `.` (aktuelles Verzeichnis).
     * Warum: Analoge Defense — Single-Dot ist kein gültiger Filename.
     * Erwartet: `InvalidAttachmentPayloadException`.
     */
    public function testSingleDotIsNotConsideredAFile(): void
    {
        $this->expectException(InvalidAttachmentPayloadException::class);
        $this->sanitizer->sanitize('.');
    }
}
