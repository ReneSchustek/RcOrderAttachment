import template from './sw-order-detail-details.html.twig';

/**
 * Setzt die Karte mit den Anhängen in den Reiter „Details" der Bestellung, direkt hinter die
 * Zusatzfelder. Nur die Vorlage wird überschrieben, weil `order` in der Ansicht schon bereitsteht.
 */
Shopware.Component.override('sw-order-detail-details', {
    template,
});
