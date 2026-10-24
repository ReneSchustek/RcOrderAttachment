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
 * Prüft den Subscriber der Bestätigungsseite: Er hört auf das richtige Ereignis und reicht das
 * Pagelet des Loaders als Erweiterung an die Seite. Ohne ihn erscheint der Upload-Bereich nicht.
 */
final class CheckoutConfirmPageSubscriberTest extends TestCase
{
    /**
     * Ein Tippfehler in Ereignis oder Methode ließe den Upload-Bereich still verschwinden.
     */
    public function testSubscribesToConfirmPageLoadedEvent(): void
    {
        $events = CheckoutConfirmPageSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(CheckoutConfirmPageLoadedEvent::class, $events);
        self::assertSame('onConfirmPageLoaded', $events[CheckoutConfirmPageLoadedEvent::class]);
    }

    /**
     * `null` vom Loader heißt: Plugin im Verkaufskanal aus. Hinge trotzdem etwas an, zeigte das
     * Template den Upload-Bereich.
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
     * Der Struct ist die einzige Quelle, aus der das Template Einstellungen und offene Uploads
     * liest. Er muss unverändert ankommen.
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
