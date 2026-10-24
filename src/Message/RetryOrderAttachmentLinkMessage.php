<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Auftrag, die Uploads des Kunden später erneut mit einer schon angelegten Bestellung zu verknüpfen.
 *
 * Scheitert das Anlegen der `rc_order_attachment`-Zeilen beim Bestellabschluss, etwa an einem
 * vorübergehenden Datenbankfehler, gingen die Dokumente für die Bestellung sonst still verloren.
 * Die Medien existieren dann bereits; die Nachricht trägt nur die Metadaten, die `attach()` braucht,
 * und keinen Dateiinhalt.
 *
 * {@see AsyncMessageInterface} hat denselben Grund wie bei {@see RetryFailedMailAttachmentsMessage}:
 * Ohne Transport-Zuordnung liefe der Wiederholversuch synchron in der Anfrage des
 * Bestellabschlusses und damit ohne jede Wartezeit.
 */
final readonly class RetryOrderAttachmentLinkMessage implements AsyncMessageInterface
{
    /**
     * @param list<array{mediaId: string, originalFileName: string, mimeType: string, fileSize: int}> $uploads
     */
    public function __construct(
        public string $orderId,
        public array $uploads,
        public int $attempt = 1,
    ) {
    }
}
