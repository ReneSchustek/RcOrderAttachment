<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Struct;

use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Shopware\Core\Framework\Struct\Struct;

/**
 * Wird je Order an die Account-Order-Page angehängt. Twig rendert die Liste
 * der hochgeladenen Anhänge mit Download-Link.
 *
 * Erfüllt DSGVO Art. 15 (Auskunft): Customer sieht im Kundenkonto, welche
 * Dateien zu seiner Bestellung gespeichert sind.
 */
final class AccountAttachmentsStruct extends Struct
{
    public function __construct(
        private readonly OrderAttachmentCollection $attachments,
    ) {
    }

    public function getAttachments(): OrderAttachmentCollection
    {
        return $this->attachments;
    }

    public function hasAttachments(): bool
    {
        return $this->attachments->count() > 0;
    }

    public function getApiAlias(): string
    {
        return 'rc_order_attachment_account_struct';
    }
}
