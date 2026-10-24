<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Checkout\Cart;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Checkout\Cart\AttachmentRequiredError;
use Ruhrcoder\RcOrderAttachment\Checkout\Cart\RequiredAttachmentValidator;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUpload;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUploadStore;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Prüft die Upload-Pflicht auf dem Server. Die Prüfung im Browser lässt sich abschalten, deshalb
 * entscheidet erst der Warenkorb-Validator, ob eine Bestellung ohne Anhang durchgeht.
 */
final class RequiredAttachmentValidatorTest extends TestCase
{
    // Ein beliebiger fester Zeitstempel (November 2023), damit der Test nicht von der Uhr abhängt.
    private const FIXED_UPLOADED_AT = 1_700_000_000;

    /**
     * Bei abgeschaltetem Plugin fehlt das Upload-Feld. Ein Fehler im Warenkorb sperrte dann den
     * Checkout, ohne dass der Kunde etwas dagegen tun kann.
     */
    public function testIgnoresWhenPluginDisabled(): void
    {
        $errors = new ErrorCollection();
        $this->makeValidator(enabled: false, required: true)->validate(new Cart('token'), $errors, $this->ctx());

        self::assertCount(0, $errors);
    }

    /**
     * Ist der Upload freiwillig, darf der Kunde auch ohne Anhang bestellen.
     */
    public function testIgnoresWhenNotRequired(): void
    {
        $errors = new ErrorCollection();
        $this->makeValidator(enabled: true, required: false)->validate(new Cart('token'), $errors, $this->ctx());

        self::assertCount(0, $errors);
    }

    /**
     * Das JavaScript lässt sich im Browser abschalten oder umgehen. Ohne offenen Upload in der
     * Sitzung muss der Server die Bestellung deshalb mit einem sperrenden Fehler aufhalten.
     */
    public function testRejectsWhenRequiredAndStoreEmpty(): void
    {
        $errors = new ErrorCollection();
        $this->makeValidator(enabled: true, required: true)->validate(new Cart('token'), $errors, $this->ctx());

        self::assertCount(1, $errors);
        self::assertInstanceOf(AttachmentRequiredError::class, $errors->first());
        self::assertTrue($errors->first()->blockOrder());
    }

    /**
     * Ein einziger offener Upload in der Sitzung erfüllt die Pflicht.
     */
    public function testAcceptsWhenAtLeastOneUploadInSession(): void
    {
        $stack = $this->requestStack();
        $store = new PendingUploadStore($stack);
        $store->add(new PendingUpload('t', 'm', 'a.pdf', 'application/pdf', 100, self::FIXED_UPLOADED_AT));

        $validator = new RequiredAttachmentValidator(
            $this->configProvider(enabled: true, required: true),
            $store,
            $stack,
        );

        $errors = new ErrorCollection();
        $validator->validate(new Cart('token'), $errors, $this->ctx());

        self::assertCount(0, $errors);
    }

    /**
     * Über die Store-API und die Admin-API gibt es keine Seite zum Hochladen, eine Sperre machte
     * etwa eine in der Verwaltung angelegte Bestellung unmöglich. Beide Anfragen tragen eine
     * Sitzung, erkennbar ist der Fall nur am Route-Scope.
     */
    public function testIgnoresStoreApiAndAdminApiOrders(): void
    {
        foreach (['store-api', 'api'] as $scope) {
            $errors = new ErrorCollection();
            $this->makeValidator(enabled: true, required: true, scope: $scope)
                ->validate(new Cart('token'), $errors, $this->ctx());

            self::assertCount(0, $errors, $scope);
        }
    }

    private function makeValidator(bool $enabled, bool $required, string $scope = 'storefront'): RequiredAttachmentValidator
    {
        $stack = $this->requestStack($scope);

        return new RequiredAttachmentValidator(
            $this->configProvider(enabled: $enabled, required: $required),
            new PendingUploadStore($stack),
            $stack,
        );
    }

    private function configProvider(bool $enabled, bool $required): PluginConfigProvider
    {
        $values = [
            'enabled' => $enabled,
            'required' => $required,
        ];

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key) => $values[substr($key, \strlen(PluginConfigProvider::DOMAIN))] ?? null,
        );

        return new PluginConfigProvider($systemConfig);
    }

    private function requestStack(string $scope = 'storefront'): RequestStack
    {
        $request = Request::create('/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->attributes->set(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE, [$scope]);
        $stack = new RequestStack();
        $stack->push($request);

        return $stack;
    }

    private function ctx(): SalesChannelContext
    {
        $stub = $this->createMock(SalesChannelContext::class);
        $stub->method('getSalesChannelId')->willReturn('sc-1');

        return $stub;
    }
}
