<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Message;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Message\RetryFailedMailAttachmentsMessage;
use Ruhrcoder\RcOrderAttachment\Message\RetryOrderAttachmentLinkMessage;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Nagelt die Transport-Zuordnung der beiden Retry-Nachrichten fest.
 *
 * Hintergrund, in der Testumgebung gemessen: Ohne Zuordnung behandelt Symfony eine Nachricht
 * synchron. Der Handler rechnet dann zwar seinen Exponential-Backoff aus und protokolliert ihn,
 * aber der `DelayStamp` wird nie ausgewertet — alle fünf Versuche liefen in **32 Millisekunden**
 * durch, bei berechneten Wartezeiten von zusammen 15 Minuten. Ein Storage-Ausfall, der länger als
 * einen Wimpernschlag dauert, verbrauchte damit sofort alle Versuche.
 *
 * Der Fehler war von aussen unsichtbar: Der Handler ist korrekt, das Log sah plausibel aus, und
 * nur die Zeitstempel verrieten es. Deshalb ein Test auf die Zuordnung selbst.
 *
 * Shopware routet `AsyncMessageInterface` per Framework-Konfiguration auf den `async`-Transport
 * (`framework.yaml`, Abschnitt `messenger.routing`) — das Interface ist die Zuordnung.
 */
final class AsyncRoutingTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function retryNachrichten(): array
    {
        return [
            'Mail-Anhang-Retry' => [RetryFailedMailAttachmentsMessage::class],
            'Order-Verknüpfungs-Retry' => [RetryOrderAttachmentLinkMessage::class],
        ];
    }

    /**
     * Was: Beide Retry-Nachrichten müssen als asynchron gekennzeichnet sein.
     * Warum: Fällt die Kennzeichnung weg, läuft der Retry wieder synchron — ohne Fehlermeldung,
     *        ohne fehlschlagenden Test, nur mit einem Backoff, der nicht mehr wartet.
     * Erwartet: Beide implementieren `AsyncMessageInterface`.
     *
     * @param class-string $nachricht
     */
    #[DataProvider('retryNachrichten')]
    public function testRetryMessagesAreRoutedAsynchronously(string $nachricht): void
    {
        self::assertTrue(
            is_subclass_of($nachricht, AsyncMessageInterface::class),
            $nachricht . ' muss AsyncMessageInterface implementieren — sonst behandelt Symfony '
            . 'die Nachricht synchron und der DelayStamp des Handlers bleibt wirkungslos',
        );
    }

    /**
     * Was: Die Nachrichten dürfen keine Kundeninhalte transportieren.
     * Warum: Sie liegen als serialisiertes Objekt in `messenger_messages` — also in der Datenbank,
     *        unverschlüsselt, bis ein Worker sie abholt. Ein Dateiname oder Mail-Inhalt hätte
     *        darin nichts zu suchen. Der Handler baut beim Retry ohnehin frisch auf.
     * Erwartet: Nur die Order-ID und Zählwerte, keine Dateinamen, Adressen oder Inhalte.
     */
    public function testMessagesCarryNoCustomerContent(): void
    {
        $mail = new RetryFailedMailAttachmentsMessage('019fad0572ff705db8e39423182987a3', 2);

        self::assertSame('019fad0572ff705db8e39423182987a3', $mail->orderId);
        self::assertSame(2, $mail->attempt);
        self::assertSame(
            ['orderId', 'attempt'],
            array_keys(get_object_vars($mail)),
            'die Nachricht liegt bis zur Abarbeitung in der Datenbank — sie bleibt ein Marker',
        );
    }
}
