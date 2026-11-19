<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ValidationCode;

/**
 * Jeder Prüfcode, den der Server liefern kann, braucht in der Vorlage seinen Text. Fehlt einer, zeigt
 * das Skript dem Kunden den Code selbst, beim Ablauf der Sitzung etwa „sessionUnavailable".
 */
final class ErrorMessagesContractTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../src/Resources/views/storefront/page/checkout/confirm/confirm-rc-order-attachment.html.twig';

    private const SNIPPETS = [
        __DIR__ . '/../../../src/Resources/snippet/de_DE/messages.de-DE.json',
        __DIR__ . '/../../../src/Resources/snippet/en_GB/messages.en-GB.json',
    ];

    public function testEveryValidationCodeHasAMessageInTheTemplate(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);

        foreach (ValidationCode::cases() as $code) {
            self::assertStringContainsString(
                \sprintf("%s: 'rcOrderAttachment.error.%s'", $code->value, $code->value),
                $template,
                "Kein Text für {$code->value} in data-error-messages",
            );
        }
    }

    /**
     * Die Meldung zu großer Dateien nennt die Grenze; der Kunde soll nicht raten, wie klein es sein muss.
     */
    public function testSizeMessagesNameTheLimit(): void
    {
        $template = (string) file_get_contents(self::TEMPLATE);
        self::assertStringContainsString("fileSizeExceeded'|trans({ '%size%': config.maxFileSizeLabel() })", $template);
        self::assertStringContainsString("totalSizeExceeded'|trans({ '%size%': config.maxTotalSizeLabel() })", $template);
        self::assertStringNotContainsString("~ ' KB'", $template);

        foreach (self::SNIPPETS as $file) {
            /** @var array{rcOrderAttachment: array{error: array<string, string>}} $snippets */
            $snippets = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
            self::assertStringContainsString('%size%', $snippets['rcOrderAttachment']['error']['fileSizeExceeded'], basename($file));
            self::assertStringContainsString('%size%', $snippets['rcOrderAttachment']['error']['totalSizeExceeded'], basename($file));
        }
    }

    public function testEveryValidationCodeHasASnippetInBothLanguages(): void
    {
        foreach (self::SNIPPETS as $file) {
            /** @var array{rcOrderAttachment: array{error: array<string, string>}} $snippets */
            $snippets = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);

            foreach (ValidationCode::cases() as $code) {
                self::assertArrayHasKey($code->value, $snippets['rcOrderAttachment']['error'], basename($file) . ": {$code->value}");
            }
        }
    }
}
