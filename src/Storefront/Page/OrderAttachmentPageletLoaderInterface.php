<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Page;

use Ruhrcoder\RcOrderAttachment\Storefront\Struct\AccountAttachmentsStruct;
use Ruhrcoder\RcOrderAttachment\Storefront\Struct\ConfirmPageUploadStruct;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Liefert die Pagelet-Daten für DSGVO-relevante Storefront-Pfade.
 * Eigene Schnittstelle, damit eine spätere Caching-Decoration ohne Eingriff
 * in Subscriber oder Controller eingehängt werden kann.
 */
interface OrderAttachmentPageletLoaderInterface
{
    /**
     * Daten für den Upload-Slot auf der Confirm-Page. `null`, wenn das Plugin
     * für den Sales-Channel deaktiviert ist — Subscriber überspringt dann das
     * Anhängen der Extension.
     */
    public function loadConfirm(Request $request, SalesChannelContext $context): ?ConfirmPageUploadStruct;

    /**
     * Anhänge pro Order für die Account-Order-Übersicht. Batch-Load gegen
     * N+1 — leere Collections sind aus dem Ergebnis ausgefiltert.
     *
     * @return array<string, AccountAttachmentsStruct> Key = Order-ID
     */
    public function loadAccount(OrderCollection $orders, Context $context): array;
}
