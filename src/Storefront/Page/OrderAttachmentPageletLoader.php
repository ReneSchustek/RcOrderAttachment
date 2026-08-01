<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Page;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUploadStore;
use Ruhrcoder\RcOrderAttachment\Storefront\Struct\AccountAttachmentsStruct;
use Ruhrcoder\RcOrderAttachment\Storefront\Struct\ConfirmPageUploadStruct;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sammelt die Daten für Confirm- und Account-Order-Page. Hält die Subscriber
 * frei von Geschäftslogik (SoC) und macht die Query-Orchestrierung testbar.
 */
final class OrderAttachmentPageletLoader implements OrderAttachmentPageletLoaderInterface
{
    public function __construct(
        private readonly PluginConfigProvider $configProvider,
        private readonly PendingUploadStore $pendingUploadStore,
        private readonly OrderAttachmentLoader $attachmentLoader,
    ) {
    }

    public function loadConfirm(Request $request, SalesChannelContext $context): ?ConfirmPageUploadStruct
    {
        $config = $this->configProvider->getForSalesChannel($context->getSalesChannelId());
        if (!$config->enabled) {
            return null;
        }

        return new ConfirmPageUploadStruct(
            $config,
            array_values($this->pendingUploadStore->all()),
        );
    }

    public function loadAccount(OrderCollection $orders, Context $context): array
    {
        if ($orders->count() === 0) {
            return [];
        }

        $grouped = $this->attachmentLoader->loadForOrderIds(array_values($orders->getIds()), $context);
        if ($grouped === []) {
            return [];
        }

        $structs = [];
        foreach ($grouped as $orderId => $attachments) {
            if ($attachments->count() === 0) {
                continue;
            }
            $structs[$orderId] = new AccountAttachmentsStruct($attachments);
        }

        return $structs;
    }
}
