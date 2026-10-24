<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Checkout\Cart;

use Shopware\Core\PlatformRequest;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Entscheidet, ob der Warenkorb gerade für eine Storefront-Seite geprüft wird.
 *
 * Hochladen lässt sich ein Anhang nur auf der Bestätigungsseite der Storefront. Über die Store-API
 * oder im Verwaltungsbereich gibt es diese Seite nicht; eine Sperre dort ließe sich nie auflösen.
 *
 * Maßgeblich ist der Route-Scope der Hauptanfrage. Eine Sitzung taugt nicht als Merkmal:
 * Shopware hängt auch `/api` und `/store-api` eine verzögert startende Sitzung an, und
 * `Request::hasSession()` meldet sie als vorhanden.
 */
final class StorefrontRequestScope
{
    public static function isStorefront(RequestStack $requestStack): bool
    {
        $scopes = $requestStack->getMainRequest()?->attributes->get(PlatformRequest::ATTRIBUTE_ROUTE_SCOPE);

        return \is_array($scopes) && \in_array(StorefrontRouteScope::ID, $scopes, true);
    }
}
