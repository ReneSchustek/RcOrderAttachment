<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Subscriber;

use Ruhrcoder\RcOrderAttachment\Storefront\Page\OrderAttachmentPageletLoaderInterface;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Hängt an jede Bestellung der Bestellübersicht im Kundenkonto ihre Anhänge als
 * {@see \Ruhrcoder\RcOrderAttachment\Storefront\Struct\AccountAttachmentsStruct}, damit der
 * Kunde sieht, welche Dateien gespeichert sind (Auskunft nach Art. 15 DSGVO).
 *
 * Das Laden übernimmt der {@see OrderAttachmentPageletLoaderInterface} mit einer Abfrage für alle
 * Bestellungen der Seite.
 */
final class AccountOrderPageSubscriber implements EventSubscriberInterface
{
    public const EXTENSION_NAME = 'rcOrderAttachmentList';

    public function __construct(
        private readonly OrderAttachmentPageletLoaderInterface $pageletLoader,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            AccountOrderPageLoadedEvent::class => 'onAccountOrderPageLoaded',
        ];
    }

    public function onAccountOrderPageLoaded(AccountOrderPageLoadedEvent $event): void
    {
        $orders = $event->getPage()->getOrders()->getEntities();
        $structs = $this->pageletLoader->loadAccount($orders, $event->getContext());
        if ($structs === []) {
            return;
        }

        foreach ($orders as $order) {
            $struct = $structs[$order->getId()] ?? null;
            if ($struct === null) {
                continue;
            }
            $order->addExtension(self::EXTENSION_NAME, $struct);
        }
    }
}
