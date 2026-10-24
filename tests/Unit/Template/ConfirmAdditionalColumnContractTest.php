<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * Hält fest, dass die Anhangskarte der Bestätigungsseite in ihrer Rasterspalte steht.
 *
 * Ohne Spalte macht Flexbox aus der Karte in `.checkout-container` einen Kasten mit Eigenbreite:
 * 450 statt 933 Pixel breit, 233 Pixel zu weit links, und rechts daneben bleiben rund 500 Pixel
 * leer. Im Browser fällt das sofort auf, im Code nicht, denn ein `sw_include` ohne
 * umgebendes `<div>` sieht unauffällig aus. Deshalb hält ein Test die Spalte fest.
 */
final class ConfirmAdditionalColumnContractTest extends TestCase
{
    private string $template;

    protected function setUp(): void
    {
        $rootDir = \dirname(__DIR__, 3) . '/src/Resources/views/storefront';
        $this->template = (string) file_get_contents($rootDir . '/page/checkout/confirm/index.html.twig');
    }

    /**
     * Die Karte steht in der Spalte, die der Kern für diesen Platz vorsieht.
     */
    public function testTheCardSitsInsideTheGridColumn(): void
    {
        $included = "<div class=\"checkout-additional\">";

        self::assertStringContainsString($included, $this->template);
        self::assertMatchesRegularExpression(
            '/<div class="checkout-additional">\s*\{% sw_include .*confirm-rc-order-attachment\.html\.twig/s',
            $this->template
        );
    }

    /**
     * Ohne `parent()` verschwände die Zusatzkarte des Kerns und mit ihr der Kundenkommentar,
     * wenn die Einstellung ihn verlangt.
     */
    public function testTheCoreAdditionalBlockSurvives(): void
    {
        self::assertStringContainsString('{{ parent() }}', $this->template);
    }

    /**
     * Die Spalte hängt an derselben Bedingung wie die Karte. Eine leere Spalte bestünde die
     * Kernprüfung `sw_block('page_checkout_additional')|trim` und nähme der Zusammenfassung ihren
     * Versatz, ohne dass links etwas steht.
     */
    public function testTheColumnIsBoundToTheExtension(): void
    {
        $position = strpos($this->template, '{% if rcAttachmentExt %}');
        $column = strpos($this->template, '<div class="checkout-additional">');

        self::assertIsInt($position);
        self::assertIsInt($column);
        self::assertGreaterThan($position, $column, 'Die Spalte muss innerhalb der Bedingung stehen.');
    }
}
