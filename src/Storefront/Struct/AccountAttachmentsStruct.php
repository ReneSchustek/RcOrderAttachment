<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Struct;

use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Shopware\Core\Framework\Struct\Struct;

/**
 * Hängt an jeder Bestellung in der Bestellübersicht des Kundenkontos; die Vorlage zeigt daraus die
 * Anhänge mit Download-Link.
 *
 * So sieht der Kunde, welche Dateien zu seiner Bestellung gespeichert sind (Auskunft nach
 * Art. 15 DSGVO).
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
