<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Media;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Kennzeichen am Context eines Medien-Schreibvorgangs, das ihn als Checkout-Upload dieses Plugins
 * ausweist.
 *
 * Der {@see \Ruhrcoder\RcOrderAttachment\Service\Subscriber\MediaWhitelistSubscriber}
 * erweitert die Endungsliste des Kerns nur, wenn das Kennzeichen am Context des Ereignisses
 * hängt. Ohne diese Bindung schaltete die Plugin-Konfiguration die Endungen im ganzen Shop frei,
 * auch für öffentliche Uploads, die mit Bestellanhängen nichts zu tun haben.
 *
 * Es trägt die Endungen, die für den Kanal des Uploads freigegeben sind und mit denen die Datei
 * geprüft wurde. Das Ereignis des Kerns kennt keinen Verkaufskanal; läse der Subscriber die
 * Konfiguration selbst, bekäme er die kanalübergreifende, und eine nur im Kanal freigegebene Endung
 * scheiterte danach im Kern.
 *
 * {@see \Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentUploadService} setzt das
 * Kennzeichen nur um den Speicheraufruf und entfernt es danach wieder. Der Context lebt über die
 * ganze Anfrage, und ein stehengebliebenes Kennzeichen öffnete die Liste für jeden weiteren
 * Upload derselben Anfrage.
 */
final class OrderAttachmentUploadScope extends Struct
{
    /**
     * Name der Context-Erweiterung, mit Plugin-Präfix, weil alle Erweiterungen im ganzen Shop
     * einen gemeinsamen Namensraum teilen.
     */
    public const NAME = 'rcOrderAttachmentUpload';

    /**
     * @param list<string> $allowedExtensions klein geschrieben, ohne die Einträge der Sperrliste
     */
    public function __construct(public readonly array $allowedExtensions = [])
    {
    }
}
