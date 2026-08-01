<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Trigger für einen späteren erneuten Versand der Bestätigungs-Mail-Anhänge.
 *
 * Nicht das Original-Event, sondern ein leichtgewichtiger Marker — der Handler
 * baut die Mail beim Retry frisch auf, damit kein Customer-Inhalt durch die
 * Queue persistiert wird.
 *
 * Implementiert bewusst {@see AsyncMessageInterface}: Shopware routet damit nach `async`.
 * Ohne diese Zuordnung behandelt Symfony die Nachricht **synchron** — der `DelayStamp` des
 * Handlers wäre wirkungslos, alle Versuche liefen im selben Request ab, und der
 * Exponential-Backoff, der dem Storage Zeit zur Erholung geben soll, verpuffte in
 * Millisekunden. Genau so gemessen, bevor dieses Interface dran war.
 */
final readonly class RetryFailedMailAttachmentsMessage implements AsyncMessageInterface
{
    public function __construct(
        public string $orderId,
        public int $attempt = 1,
    ) {
    }
}
