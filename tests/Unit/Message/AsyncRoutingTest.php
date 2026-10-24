<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Message;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Message\RetryFailedMailAttachmentsMessage;
use Ruhrcoder\RcOrderAttachment\Message\RetryOrderAttachmentLinkMessage;
use Shopware\Core\Framework\MessageQueue\AsyncMessageInterface;

/**
 * Hält fest, dass beide Wiederholungsnachrichten über den asynchronen Transport laufen.
 *
 * Ohne Zuordnung behandelt Symfony eine Nachricht synchron. Der Handler rechnet seine
 * wachsenden Wartezeiten dann zwar aus und protokolliert sie, aber niemand wertet den
 * `DelayStamp` aus. In der Testumgebung laufen so alle fünf Versuche in rund 30 Millisekunden durch,
 * bei berechneten Wartezeiten von zusammen 15 Minuten; ein Speicherausfall von mehr als einem
 * Augenblick verbraucht damit sofort alle Versuche.
 *
 * Von außen ist das unsichtbar, weil Handler und Protokoll plausibel aussehen und nur die
 * Zeitstempel es verraten. Deshalb prüft der Test die Zuordnung selbst. Shopware leitet
 * `AsyncMessageInterface` über `messenger.routing` in `framework.yaml` auf den Transport `async`,
 * das Interface ist also die Zuordnung.
 */
final class AsyncRoutingTest extends TestCase
{
    /**
     * @return array<string, array{class-string}>
     */
    public static function retryMessages(): array
    {
        return [
            'Mail-Anhang-Retry' => [RetryFailedMailAttachmentsMessage::class],
            'Order-Verknüpfungs-Retry' => [RetryOrderAttachmentLinkMessage::class],
        ];
    }

    /**
     * Fällt die Kennzeichnung weg, läuft die Wiederholung synchron, ohne Fehlermeldung und mit
     * Wartezeiten, die nicht warten.
     *
     * @param class-string $message
     */
    #[DataProvider('retryMessages')]
    public function testRetryMessagesAreRoutedAsynchronously(string $message): void
    {
        self::assertTrue(
            is_subclass_of($message, AsyncMessageInterface::class),
            $message . ' muss AsyncMessageInterface implementieren — sonst behandelt Symfony '
            . 'die Nachricht synchron und der DelayStamp des Handlers bleibt wirkungslos',
        );
    }

    /**
     * Die Nachricht liegt serialisiert und unverschlüsselt in `messenger_messages`, bis ein
     * Worker sie abholt. Sie trägt deshalb nur Bestell-ID und Versuchszähler; den Rest lädt der
     * Handler beim Wiederholen frisch.
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
