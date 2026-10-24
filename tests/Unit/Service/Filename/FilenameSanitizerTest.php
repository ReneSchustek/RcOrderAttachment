<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Filename;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Exception\InvalidAttachmentPayloadException;
use Ruhrcoder\RcOrderAttachment\Service\Filename\FilenameSanitizer;

/**
 * Prüft die Bereinigung des Dateinamens: Pfadbestandteile, Zeilenumbrüche und Steuerzeichen,
 * Unicode-Richtungszeichen, Punkte am Rand und die Längengrenze. Der Name landet in Datenbank,
 * Mailanhang und Verwaltung, und jede dieser Stellen ist für eine andere Sorte Zeichen anfällig.
 */
final class FilenameSanitizerTest extends TestCase
{
    private FilenameSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new FilenameSanitizer();
    }

    /**
     * Ein harmloser Name bleibt unverändert, sonst ginge der Name verloren, unter dem der Kunde
     * die Datei kennt.
     */
    public function testHarmlessFilenamePassesUnchanged(): void
    {
        self::assertSame('drawing.pdf', $this->sanitizer->sanitize('drawing.pdf'));
    }

    /**
     * Leerzeichen am Rand entstehen leicht beim Kopieren, und Dateisysteme gehen unterschiedlich
     * mit ihnen um.
     */
    public function testTrimsLeadingTrailingWhitespace(): void
    {
        self::assertSame('drawing.pdf', $this->sanitizer->sanitize("  drawing.pdf  "));
    }

    /**
     * Vom Namen bleibt nur der letzte Pfadbestandteil, auch bei Windows-Trennern. Sonst könnte ein
     * Name mit `../` aus dem Zielverzeichnis herausführen.
     */
    public function testStripsPathComponents(): void
    {
        self::assertSame('passwd', $this->sanitizer->sanitize('../../../etc/passwd'));
        self::assertSame('config.ini', $this->sanitizer->sanitize('C:\\Windows\\System32\\config.ini'));
    }

    /**
     * Ein CRLF im Namen, der später in einem Mailkopf oder einer Protokollzeile steht, könnte
     * dort weitere Kopfzeilen oder Einträge einschleusen. Jedes Zeichen wird einzeln zu `_`.
     */
    public function testReplacesCrLfTabNull(): void
    {
        self::assertSame('report__forwarded.pdf', $this->sanitizer->sanitize("report\r\nforwarded.pdf"));
        self::assertSame('a_b.pdf', $this->sanitizer->sanitize("a\tb.pdf"));
        self::assertSame('a_b.pdf', $this->sanitizer->sanitize("a\0b.pdf"));
    }

    /**
     * Die übrigen ASCII-Steuerzeichen und DEL können in Mailprogrammen oder auf einem Terminal
     * unerwartet wirken und werden ebenfalls ersetzt.
     */
    public function testStripsAsciiControlCharacters(): void
    {
        $input = "report\x01\x02\x1F\x7F.pdf";
        $output = $this->sanitizer->sanitize($input);
        self::assertSame('report____.pdf', $output);
    }

    /**
     * Richtungszeichen täuschen über die Endung: `cute<U+202E>gpj.exe` erscheint im
     * Dateimanager als `cuteexe.jpg`. Jedes Zeichen aus U+202A bis U+202E und U+2066 bis U+2069
     * wird zu `_`, alles andere bleibt in seiner Reihenfolge.
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
     * Ein Punkt am Anfang macht unter Unix eine versteckte Datei wie `.htaccess`, Punkte am Ende
     * verschluckt Windows. Beides fällt am Rand weg.
     */
    public function testTrimsLeadingTrailingDots(): void
    {
        self::assertSame('pdf', $this->sanitizer->sanitize('....pdf'));
        self::assertSame('drawing.pdf', $this->sanitizer->sanitize('drawing.pdf...'));
        self::assertSame('drawing.pdf', $this->sanitizer->sanitize('...drawing.pdf...'));
    }

    /**
     * Die Spalte `original_file_name` ist `VARCHAR(255)`, im strikten SQL-Modus scheiterte ein
     * längerer Wert beim Einfügen. Gezählt wird in Zeichen, nicht in Byte.
     */
    public function testTrimsToMaxLength(): void
    {
        $long = str_repeat('a', 300) . '.pdf';
        $result = $this->sanitizer->sanitize($long);
        self::assertSame(FilenameSanitizer::MAX_LENGTH, mb_strlen($result));
    }

    /**
     * Umlaute und andere Schriften sind in Kundennamen alltäglich und bleiben erhalten. Entfernt
     * werden nur Richtungs- und Steuerzeichen.
     */
    public function testHandlesMultibyteUnicodeCorrectly(): void
    {
        self::assertSame('Größe-Übermaß.pdf', $this->sanitizer->sanitize('Größe-Übermaß.pdf'));
        self::assertSame('文件.pdf', $this->sanitizer->sanitize('文件.pdf'));
    }

    /**
     * Ein leerer Name deutet auf einen Programmfehler oder kaputten Upload. Stillschweigend
     * zurückgegeben, landete er als leerer Text in der Datenbank.
     */
    public function testThrowsOnEmptyInput(): void
    {
        $this->expectException(InvalidAttachmentPayloadException::class);
        $this->sanitizer->sanitize('');
    }

    /**
     * Nach dem Kürzen der Ränder bleibt nichts übrig, also derselbe Fehler wie bei leerer Eingabe.
     */
    public function testThrowsOnWhitespaceOnlyInput(): void
    {
        $this->expectException(InvalidAttachmentPayloadException::class);
        $this->sanitizer->sanitize("   \t\r\n   ");
    }

    /**
     * Grenzfall: Richtungszeichen werden ersetzt, nicht entfernt. Ein Name nur aus ihnen ergibt
     * deshalb einen Unterstrich je Zeichen und keinen Fehler.
     */
    public function testBidiOnlyInputReplacedWithUnderscores(): void
    {
        self::assertSame('__', $this->sanitizer->sanitize("\xE2\x80\xAE\xE2\x80\xAA"));
    }

    /**
     * `basename('..')` liefert `..`, nach dem Kürzen der Punkte bleibt nichts. Das übergeordnete
     * Verzeichnis ist kein Dateiname.
     */
    public function testDoubleDotIsNotConsideredAFile(): void
    {
        $this->expectException(InvalidAttachmentPayloadException::class);
        $this->sanitizer->sanitize('..');
    }

    /**
     * Ebenso wenig das aktuelle Verzeichnis `.`.
     */
    public function testSingleDotIsNotConsideredAFile(): void
    {
        $this->expectException(InvalidAttachmentPayloadException::class);
        $this->sanitizer->sanitize('.');
    }
}
