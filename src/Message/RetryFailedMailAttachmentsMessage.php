<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Message;

use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Auftrag, die Anhänge einer Bestellbestätigung später erneut auf Lesbarkeit zu prüfen und den
 * Anhangstatus der Bestellung nachzutragen. Die Mail selbst verschickt der Handler nicht.
 *
 * Die Nachricht trägt nur die Bestell-ID und die Versuchsnummer, nicht das ursprüngliche
 * Ereignis. So liegt kein Inhalt des Kunden in der Warteschlange.
 *
 * {@see AsyncMessageInterface} leitet die Nachricht auf den Transport `async`. Ohne diese
 * Zuordnung behandelt Symfony sie synchron: Der `DelayStamp` des Handlers bliebe wirkungslos, alle
 * Versuche liefen in derselben Anfrage ab, und die wachsende Wartezeit, die dem Speicher Zeit zur
 * Erholung geben soll, verpuffte in Millisekunden.
 */
final readonly class RetryFailedMailAttachmentsMessage implements AsyncMessageInterface
{
    public function __construct(
        public string $orderId,
        public int $attempt = 1,
    ) {
    }
}
