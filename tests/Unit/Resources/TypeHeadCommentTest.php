<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Jeder Typ unter `src/` trägt einen Kopfkommentar: wofür es ihn gibt und welche Entscheidung
 * dahinter steht.
 *
 * Geprüft wird nur, dass er da ist; ob er stimmt, kann kein Test sagen. Der Wächter fängt den neuen
 * Typ, der ohne jede Erklärung hinzukommt. Die Gegenprobe darunter zeigt, dass die Erkennung einen
 * fehlenden Kopf auch wirklich bemerkt.
 */
final class TypeHeadCommentTest extends TestCase
{
    public function testEveryTypeInSrcHasAHeadComment(): void
    {
        $root = \dirname(__DIR__, 3);
        $missing = [];
        $checked = 0;

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $verdict = self::hasHeadComment($source);
            if ($verdict === null) {
                continue;
            }

            ++$checked;
            if ($verdict === false) {
                $missing[] = substr($file->getPathname(), \strlen($root) + 1);
            }
        }

        // Ohne diese Zahl bliebe ein Wächter, der aus Versehen keine Datei mehr findet, still grün.
        self::assertGreaterThan(50, $checked, 'Der Wächter findet kaum noch Typen; stimmt der Pfad?');
        self::assertSame([], $missing, 'Typen ohne Kopfkommentar');
    }

    public function testTheCheckNoticesAMissingHead(): void
    {
        self::assertFalse(self::hasHeadComment("<?php\n\nnamespace A;\n\nfinal class B\n{\n}\n"));
        self::assertFalse(self::hasHeadComment("<?php\n\n// nur eine Zeile\nfinal class B\n{\n}\n"));
        self::assertTrue(self::hasHeadComment("<?php\n\n/**\n * Wofür.\n */\nfinal class B\n{\n}\n"));
        self::assertTrue(self::hasHeadComment("<?php\n\n/**\n * Wofür.\n */\n#[Package('x')]\nclass B\n{\n}\n"));
        self::assertTrue(self::hasHeadComment("<?php\n\n/**\n * Wofür.\n */\n#[Route(defaults: ['a' => ['b']])]\nclass B\n{\n}\n"));
        self::assertNull(self::hasHeadComment("<?php\n\nreturn [];\n"));
    }

    /**
     * @return bool|null `null`, wenn die Datei keinen Typ deklariert
     */
    private static function hasHeadComment(string $source): ?bool
    {
        if (1 !== preg_match('/^(?:(?:final|abstract|readonly) )*(?:class|interface|enum|trait) \w+/m', $source, $match, \PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $before = rtrim(substr($source, 0, $match[0][1]));

        // Attribute stehen zwischen Kommentar und Typ; sie zählen nicht als Kopf. Abgeschnitten wird ab
        // dem letzten `#[` am Zeilenanfang, weil ein Attribut selbst Klammern enthalten kann.
        while (str_ends_with($before, ']') && ($start = strrpos($before, "\n#[")) !== false) {
            $before = rtrim(substr($before, 0, $start));
        }

        return str_ends_with($before, '*/');
    }
}
