<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Media;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Marker am Context eines Media-Schreibvorgangs: belegt, dass er aus dem
 * Checkout-Upload dieses Plugins stammt.
 *
 * Der {@see \Ruhrcoder\RcOrderAttachment\Service\Subscriber\MediaWhitelistSubscriber}
 * erweitert die Core-Whitelist ausschließlich, wenn dieser Marker am Context des
 * Events hängt. Ohne ihn würde die Plugin-Konfiguration die Endungen shopweit
 * freischalten — auch für öffentliche Uploads, die mit Bestell-Anhängen nichts zu
 * tun haben.
 *
 * Der Marker wird in
 * {@see \Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentUploadService} nur um
 * den Speicheraufruf herum gesetzt und danach wieder entfernt — der Context lebt
 * über den ganzen Request, ein stehengelassener Marker würde die Ausweitung für
 * jeden weiteren Upload desselben Requests wieder öffnen.
 */
final class OrderAttachmentUploadScope extends Struct
{
    /**
     * Name der Context-Extension. Plugin-Präfix, weil Extensions shopweit in
     * einem Namensraum liegen.
     */
    public const NAME = 'rcOrderAttachmentUpload';
}
