import template from './sw-order-detail-details.html.twig';

/**
 * Hängt die Bestell-Anhang-Card an den Detail-Tab der Bestellung an — direkt
 * hinter die Zusatzfelder. Reiner Template-Override, keine Logik: die `order`
 * steht im Detail-View bereits im Scope.
 */
Shopware.Component.override('sw-order-detail-details', {
    template,
});
