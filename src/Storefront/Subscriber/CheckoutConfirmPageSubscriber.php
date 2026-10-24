<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Subscriber;

use Ruhrcoder\RcOrderAttachment\Storefront\Page\OrderAttachmentPageletLoaderInterface;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt die Daten der Upload-Box an die Bestätigungsseite, aus denen die Vorlage den Bereich
 * zeichnet. Gesammelt werden sie im {@see OrderAttachmentPageletLoaderInterface}; fehlt die
 * Erweiterung, weil das Plugin im Kanal aus ist, bleibt der Bereich weg.
 */
final class CheckoutConfirmPageSubscriber implements EventSubscriberInterface
{
    public const EXTENSION_NAME = 'rcOrderAttachmentUpload';

    public function __construct(
        private readonly OrderAttachmentPageletLoaderInterface $pageletLoader,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'onConfirmPageLoaded',
        ];
    }

    public function onConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $pagelet = $this->pageletLoader->loadConfirm($event->getRequest(), $event->getSalesChannelContext());
        if ($pagelet === null) {
            return;
        }

        $event->getPage()->addExtension(self::EXTENSION_NAME, $pagelet);
    }
}
