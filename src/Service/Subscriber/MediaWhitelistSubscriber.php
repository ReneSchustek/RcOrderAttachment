<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Subscriber;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\Media\OrderAttachmentUploadScope;
use Shopware\Core\Content\Media\Event\MediaFileExtensionWhitelistEvent;
use Shopware\Core\Content\Media\MediaException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Erweitert die Shopware-Whitelist der erlaubten Datei-Endungen um die in der
 * Plugin-Konfiguration freigegebenen — und zwar nur für Uploads dieses Plugins.
 *
 * Beispiel: Admin trägt `dwg` in die Plugin-Whitelist ein. Ohne diesen
 * Subscriber würde `FileSaver::validateFileExtension` mit
 * `MEDIA_FILE_EXTENSION_NOT_SUPPORTED` aussteigen — weil `dwg` nicht in
 * Shopwares `private_allowed_extensions` steht.
 *
 * Der Core dispatcht das Event für **jeden** Media-Upload und unterscheidet dabei
 * nur zwischen öffentlicher und privater Whitelist
 * ({@see \Shopware\Core\Content\Media\Upload\MediaFileExtensionValidator}) — ein
 * Merkmal „stammt aus diesem Plugin" trägt es nicht. Deshalb markiert der
 * Upload-Service seinen Context mit {@see OrderAttachmentUploadScope} und dieser
 * Subscriber erweitert nur bei gesetztem Marker. Ohne die Prüfung würde die
 * Plugin-Konfiguration zur Shop-Konfiguration.
 *
 * Die hart kodierte Blacklist im {@see \Ruhrcoder\RcOrderAttachment\Service\Validation\DangerousContentValidator}
 * läuft VORHER und sortiert gefährliche Endungen vor der Whitelist-Erweiterung
 * raus — wir erweitern hier also nur das, was schon validiert wurde.
 */
final class MediaWhitelistSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly PluginConfigProvider $configProvider,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MediaFileExtensionWhitelistEvent::class => 'onWhitelist',
        ];
    }

    public function onWhitelist(MediaFileExtensionWhitelistEvent $event): void
    {
        if (!$this->isPluginUpload($event)) {
            return;
        }

        $config = $this->configProvider->getForSalesChannel();
        if (!$config->enabled || $config->allowedExtensions === []) {
            return;
        }

        $existing = array_map('strtolower', $event->getWhitelist());
        $merged = array_values(array_unique(array_merge($existing, $config->allowedExtensions)));

        $event->setWhitelist($merged);
    }

    /**
     * Belegt der Context, dass der Upload aus diesem Plugin stammt?
     *
     * Fail-closed: Lässt sich die Herkunft nicht belegen, bleibt die Core-Whitelist
     * unangetastet. Der Context ist im Event bis Shopware 6.8 optional; fehlt er,
     * wirft {@see MediaFileExtensionWhitelistEvent::getContext()}. Dieser Fall
     * kommt auf keinem Core-Pfad vor — die Absicherung verhindert nur, dass ein
     * fremder Dispatcher ohne Context jeden Media-Upload im Shop scheitern lässt.
     */
    private function isPluginUpload(MediaFileExtensionWhitelistEvent $event): bool
    {
        try {
            return $event->getContext()->hasExtension(OrderAttachmentUploadScope::NAME);
        } catch (MediaException) {
            return false;
        }
    }
}
