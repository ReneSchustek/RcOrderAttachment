<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Validation;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ValidationCode;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ValidationResult;

/**
 * Unit-Tests des Validierungs-Sammlers. Sichert das Akkumulieren mehrerer
 * Fails (kein Fail-fast), die Reihenfolge und den Snippet-Key-Export.
 */
final class ValidationResultTest extends TestCase
{
    /**
     * Was: Frisches `ValidationResult` ohne Fails.
     * Warum: Default-Zustand muss "valid" sein, sonst würden alle Uploads ohne
     *        explizites Fail trotzdem als invalid gelten.
     * Erwartet: `isValid() = true`, `firstCode() = null`, leere Listen.
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
     * Was: Zwei `fail()`-Aufrufe in Folge.
     * Warum: Wir akkumulieren bewusst (kein Fail-Fast) — Customer soll alle
     *        Probleme einer Datei in einem Roundtrip sehen, nicht den erst-
     *        besten Fehler. Reihenfolge wird benötigt, weil `firstCode()` der
     *        Default-Snippet-Key fürs Toast ist.
     * Erwartet: `isValid() = false`, `firstCode()` = erster Fail,
     *           `getCodeValues()` enthält beide in Reihenfolge, Context bleibt erhalten.
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
