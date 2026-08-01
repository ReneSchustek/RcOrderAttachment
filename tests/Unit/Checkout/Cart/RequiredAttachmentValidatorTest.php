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
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Unit-Tests für die serverseitige Erzwingung der „Upload Pflicht"-Konfiguration.
 * Verifiziert die vier Branches des Cart-Validators: Plugin aus, Pflicht aus,
 * Pflicht an + leerer Store (Reject), Pflicht an + mindestens ein Upload (OK).
 */
final class RequiredAttachmentValidatorTest extends TestCase
{
    private const FIXED_UPLOADED_AT = 1_700_000_000;

    /**
     * Was: Plugin in der Konfiguration deaktiviert.
     * Warum: Wenn der Operator das Plugin abgeschaltet hat, darf der Validator
     *        keine Cart-Errors mehr produzieren, sonst ist der Checkout
     *        blockiert ohne sichtbare Plugin-UI.
     * Erwartet: Keine Errors in der ErrorCollection.
     */
    public function testIgnoresWhenPluginDisabled(): void
    {
        $errors = new ErrorCollection();
        $this->makeValidator(enabled: false, required: true)->validate(new Cart('token'), $errors, $this->ctx());

        self::assertCount(0, $errors);
    }

    /**
     * Was: Plugin aktiv, Upload-Pflicht-Toggle aus.
     * Warum: Customer darf bei Optional-Upload-Konfiguration immer durchchecken,
     *        auch ohne Anhang. Sonst zwingen wir Käufer unnötig zum Upload.
     * Erwartet: Keine Errors.
     */
    public function testIgnoresWhenNotRequired(): void
    {
        $errors = new ErrorCollection();
        $this->makeValidator(enabled: true, required: false)->validate(new Cart('token'), $errors, $this->ctx());

        self::assertCount(0, $errors);
    }

    /**
     * Was: Plugin aktiv, Pflicht aktiv, aber kein Pending-Upload in der Session.
     * Warum: Server-seitiges Enforcement — JS-Plugin kann im Browser deaktiviert
     *        oder umgangen werden, der Server muss die letzte Verteidigungslinie
     *        gegen Bestellungen ohne Pflicht-Anhang sein.
     * Erwartet: Genau ein `AttachmentRequiredError` mit `blockOrder() = true`.
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
     * Was: Plugin aktiv, Pflicht aktiv, mindestens ein Pending-Upload in der Session.
     * Warum: Happy-Path — Customer hat Pflicht erfüllt, Checkout darf weiter.
     * Erwartet: Keine Errors.
     */
    public function testAcceptsWhenAtLeastOneUploadInSession(): void
    {
        $store = $this->store();
        $store->add(new PendingUpload('t', 'm', 'a.pdf', 'application/pdf', 100, self::FIXED_UPLOADED_AT));

        $validator = new RequiredAttachmentValidator(
            $this->configProvider(enabled: true, required: true),
            $store,
        );

        $errors = new ErrorCollection();
        $validator->validate(new Cart('token'), $errors, $this->ctx());

        self::assertCount(0, $errors);
    }

    private function makeValidator(bool $enabled, bool $required): RequiredAttachmentValidator
    {
        return new RequiredAttachmentValidator(
            $this->configProvider(enabled: $enabled, required: $required),
            $this->store(),
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

    private function store(): PendingUploadStore
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        return new PendingUploadStore($stack);
    }

    private function ctx(): SalesChannelContext
    {
        $stub = $this->createMock(SalesChannelContext::class);
        $stub->method('getSalesChannelId')->willReturn('sc-1');

        return $stub;
    }
}
