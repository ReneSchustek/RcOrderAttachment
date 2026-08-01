<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Subscriber;

use Ruhrcoder\RcOrderAttachment\Storefront\Page\OrderAttachmentPageletLoaderInterface;
use Shopware\Storefront\Page\Account\Order\AccountOrderPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event-Adapter: delegiert das Batch-Loading der Anhänge an den
 * {@see OrderAttachmentPageletLoaderInterface} und hängt pro Order eine
 * {@see \Ruhrcoder\RcOrderAttachment\Storefront\Struct\AccountAttachmentsStruct}
 * an. Pflicht nach DSGVO Art. 15 (Auskunft).
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
