<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Validation;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ValidationCode;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ValidationResult;

/**
 * Prüft das Ergebnis einer Validierung: Es sammelt alle Fehler in ihrer Reihenfolge, statt beim
 * ersten aufzuhören, und gibt ihre Codes als Textbausteinschlüssel aus.
 */
final class ValidationResultTest extends TestCase
{
    /**
     * Ohne Fehler ist das Ergebnis gültig. Wäre es das nicht, scheiterte jeder Upload.
     */
    public function testEmptyIsValid(): void
    {
        $result = new ValidationResult();

        self::assertTrue($result->isValid());
        self::assertNull($result->firstCode());
        self::assertSame([], $result->getFailures());
        self::assertSame([], $result->getCodeValues());
    }

    /**
     * Der Kunde soll alle Probleme einer Datei auf einmal sehen. `firstCode()` liefert den
     * ersten Fehler, und der Kontext jedes Fehlers bleibt für die Meldung erhalten.
     */
    public function testFailuresCollectInOrder(): void
    {
        $result = new ValidationResult();
        $result->fail(ValidationCode::FILE_SIZE_EXCEEDED, ['sizeKb' => 99]);
        $result->fail(ValidationCode::MIME_NOT_ALLOWED);

        self::assertFalse($result->isValid());
        self::assertSame(ValidationCode::FILE_SIZE_EXCEEDED, $result->firstCode());
        self::assertSame(['fileSizeExceeded', 'mimeNotAllowed'], $result->getCodeValues());
        self::assertSame(99, $result->getFailures()[0]['context']['sizeKb']);
    }
}
