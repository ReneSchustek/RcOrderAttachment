<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Trigger für einen späteren erneuten Versuch, Customer-Uploads mit einer bereits
 * angelegten Bestellung zu verknüpfen.
 *
 * Hintergrund: Scheitert das Verknüpfen (`rc_order_attachment`-Create) beim
 * Order-Placement komplett — z. B. ein transienter DB-Fehler — wären die vom
 * Customer hochgeladenen Dokumente sonst still für die Bestellung verloren. Das
 * Media existiert bereits; dieser Marker trägt die Metadaten, die der Handler
 * zum idempotenten Nachverknüpfen braucht (er überspringt bereits verlinkte).
 *
 * Es wird bewusst nur die für den `attach()`-Aufruf nötige, nicht-sensible
 * Metadaten-Menge durch die Queue gereicht (kein Datei-Inhalt).
 *
 * Implementiert {@see AsyncMessageInterface} aus demselben Grund wie
 * {@see RetryFailedMailAttachmentsMessage}: ohne Transport-Zuordnung liefe der Retry synchron
 * im Request des Bestellabschlusses und damit ohne jede Wartezeit.
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
