<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Ruhrcoder\RcOrderAttachment\Storefront\Page\OrderAttachmentPageletLoaderInterface;
use Ruhrcoder\RcOrderAttachment\Storefront\Struct\ConfirmPageUploadStruct;
use Ruhrcoder\RcOrderAttachment\Storefront\Subscriber\CheckoutConfirmPageSubscriber;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit-Tests für den Event-Adapter auf der Confirm-Page. Verifiziert, dass der
 * Subscriber das Confirm-Page-Loaded-Event korrekt abonniert und das vom
 * Loader gelieferte Pagelet als Twig-Extension durchreicht.
 */
final class CheckoutConfirmPageSubscriberTest extends TestCase
{
    /**
     * Was: Statische Event-Subscription.
     * Warum: Der Subscriber muss exakt auf `CheckoutConfirmPageLoadedEvent` und
     *        die Methode `onConfirmPageLoaded` registriert sein — eine
     *        Tippfehler-Verschiebung würde den gesamten Upload-Slot
     *        unsichtbar machen.
     * Erwartet: Event ist gemappt auf `onConfirmPageLoaded`.
     */
    public function testSubscribesToConfirmPageLoadedEvent(): void
    {
        $events = CheckoutConfirmPageSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(CheckoutConfirmPageLoadedEvent::class, $events);
        self::assertSame('onConfirmPageLoaded', $events[CheckoutConfirmPageLoadedEvent::class]);
    }

    /**
     * Was: Loader liefert `null` (Plugin für den Sales-Channel deaktiviert).
     * Warum: Wenn das Plugin in der Konfiguration aus ist, darf nichts an die
     *        Page angehängt werden — das Twig-Template würde sonst trotz
     *        Deaktivierung den Upload-Block rendern.
     * Erwartet: Page hat keine `rcOrderAttachmentUpload`-Extension.
     */
    public function testSkipsWhenLoaderReturnsNull(): void
    {
        $loader = $this->createMock(OrderAttachmentPageletLoaderInterface::class);
        $loader->method('loadConfirm')->willReturn(null);

        $event = $this->event();
        (new CheckoutConfirmPageSubscriber($loader))->onConfirmPageLoaded($event);

        self::assertNull($event->getPage()->getExtension(CheckoutConfirmPageSubscriber::EXTENSION_NAME));
    }

    /**
     * Was: Loader liefert ein `ConfirmPageUploadStruct`.
     * Warum: Happy-Path — das Struct ist die einzige Quelle, aus der das
     *        Twig-Template die Upload-Konfiguration und Pending-Uploads zieht.
     * Erwartet: Page hat die Extension mit exakt dem Struct, das der Loader
     *           geliefert hat.
     */
    public function testAttachesExtensionWhenLoaderReturnsStruct(): void
    {
        $struct = new ConfirmPageUploadStruct($this->config(), []);

        $loader = $this->createMock(OrderAttachmentPageletLoaderInterface::class);
        $loader->method('loadConfirm')->willReturn($struct);

        $event = $this->event();
        (new CheckoutConfirmPageSubscriber($loader))->onConfirmPageLoaded($event);

        self::assertSame($struct, $event->getPage()->getExtension(CheckoutConfirmPageSubscriber::EXTENSION_NAME));
    }

    private function event(): CheckoutConfirmPageLoadedEvent
    {
        $page = new CheckoutConfirmPage();
        $page->setCart(new Cart('checkout-token'));

        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        return new CheckoutConfirmPageLoadedEvent($page, $salesChannelContext, new Request());
    }

    private function config(): PluginConfig
    {
        return new PluginConfig(
            enabled: true,
            required: false,
            maxFiles: 5,
            maxFileSizeKb: 10240,
            maxTotalSizeKb: 40960,
            allowedExtensions: ['pdf'],
            orphanRetentionHours: 24,
            attachmentRetentionDays: 180,
            attachToConfirmationMail: true,
            stripExifFromImages: true,
            rateLimitPerMinute: 30,
            mailTemplateWhitelist: ['order_confirmation_mail'],
        );
    }
}
