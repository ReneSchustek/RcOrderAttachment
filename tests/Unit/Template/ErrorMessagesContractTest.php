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
