<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Subscriber;

use Ruhrcoder\RcOrderAttachment\Storefront\Page\OrderAttachmentPageletLoaderInterface;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event-Adapter: delegiert das Sammeln der Upload-Daten an den
 * {@see OrderAttachmentPageletLoaderInterface} und hängt das Resultat als
 * Page-Extension an. Twig rendert die Upload-Section darauf basierend.
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
