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
 * Liefert die Daten für die Upload-Box auf der Bestätigungsseite und die Anhangsliste im
 * Kundenkonto. Die Schnittstelle erlaubt einen Dekorator, etwa für einen Zwischenspeicher, ohne
 * die Subscriber anzufassen.
 */
interface OrderAttachmentPageletLoaderInterface
{
    /**
     * Daten für die Upload-Box auf der Bestätigungsseite. `null`, wenn das Plugin für den
     * Verkaufskanal abgeschaltet ist; der Subscriber hängt dann nichts an die Seite.
     */
    public function loadConfirm(Request $request, SalesChannelContext $context): ?ConfirmPageUploadStruct;

    /**
     * Anhänge je Bestellung für die Bestellübersicht im Kundenkonto, mit einer Abfrage für alle
     * Bestellungen. Bestellungen ohne Anhang fehlen im Ergebnis.
     *
     * @return array<string, AccountAttachmentsStruct> Schlüssel ist die Bestell-ID
     */
    public function loadAccount(OrderCollection $orders, Context $context): array;
}
