<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Checkout\Cart;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUploadStore;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Serverseitige Erzwingung der „Upload Pflicht"-Konfiguration.
 *
 * Läuft auf jeder Cart-Berechnung und beim Order-Placement. Liefert einen
 * `AttachmentRequiredError`, sobald die Pflicht aktiv und die Session leer ist.
 */
final class RequiredAttachmentValidator implements CartValidatorInterface
{
    public function __construct(
        private readonly PluginConfigProvider $configProvider,
        private readonly PendingUploadStore $pendingUploadStore,
    ) {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        $config = $this->configProvider->getForSalesChannel($context->getSalesChannelId());
        if (!$config->enabled || !$config->required) {
            return;
        }

        // Ohne Storefront-Session (Store-API/Headless/manuelle Admin-Bestellung)
        // gibt es keine Confirm-Page und damit keine Upload-Möglichkeit. Die
        // Pflicht dort zu erzwingen würde solche Bestellungen dauerhaft blocken —
        // die Erzwingung greift nur im Session-Kontext (Storefront-Checkout).
        if (!$this->pendingUploadStore->sessionAvailable()) {
            return;
        }

        if ($this->pendingUploadStore->count() > 0) {
            return;
        }

        $errors->add(new AttachmentRequiredError());
    }
}
