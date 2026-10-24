<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Checkout\Cart;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUploadStore;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartValidatorInterface;
use Shopware\Core\Checkout\Cart\Error\ErrorCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Setzt die Einstellung „Anhang ist Pflicht" auf dem Server durch.
 *
 * Shopware ruft den Prüfer bei jeder Warenkorbberechnung und vor dem Anlegen der Bestellung auf.
 * Er meldet `AttachmentRequiredError`, sobald die Pflicht gilt, die Anfrage aus der Storefront
 * kommt und in der Sitzung noch kein Upload vorgemerkt ist.
 */
final class RequiredAttachmentValidator implements CartValidatorInterface
{
    public function __construct(
        private readonly PluginConfigProvider $configProvider,
        private readonly PendingUploadStore $pendingUploadStore,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function validate(Cart $cart, ErrorCollection $errors, SalesChannelContext $context): void
    {
        $config = $this->configProvider->getForSalesChannel($context->getSalesChannelId());
        if (!$config->enabled || !$config->required) {
            return;
        }

        // Außerhalb der Storefront fehlt die Seite, auf der sich ein Anhang hochladen ließe.
        if (!StorefrontRequestScope::isStorefront($this->requestStack)) {
            return;
        }

        if ($this->pendingUploadStore->count() > 0) {
            return;
        }

        $errors->add(new AttachmentRequiredError());
    }
}
