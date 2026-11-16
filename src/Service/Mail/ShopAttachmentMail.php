<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Mail;

/**
 * Die eigene Mail an den Shop, die als einzige die Kundendateien trägt.
 *
 * Bis 1.0.12 hingen die Dateien an der Bestellbestätigung und gingen damit auch an den Kunden, der sie selbst hochgeladen
 * hat. Am 09.11.2026 scheiterten so beide Bestellmails einer Bestellung an der Größengrenze des Mailservers.
 */
final class ShopAttachmentMail
{
    public const TEMPLATE_TYPE = 'rc_order_attachment.shop_notification';

    /** Schlüssel in den Vorlagendaten, über den die Mail dem Subscriber sagt, ob er anhängen soll. */
    public const DATA_KEY = 'rcOrderAttachment';

    /**
     * Ob die Dateien als Anhang mitgehen. Darüber liegen sie nur in der Verwaltung, die Mail nennt sie.
     */
    public static function fitsIntoMail(int $totalBytes, int $maxSizeKb): bool
    {
        return $maxSizeKb > 0 && $totalBytes <= $maxSizeKb * 1024;
    }

    /**
     * Die eingestellte Adresse geht vor, sonst die Adresse des Shops aus den Grundeinstellungen.
     */
    public static function recipient(string $configured, string $shopEmail): ?string
    {
        foreach ([$configured, $shopEmail] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, \FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        return null;
    }
}
