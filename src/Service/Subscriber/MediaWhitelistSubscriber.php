<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Subscriber;

use Ruhrcoder\RcOrderAttachment\Service\Media\OrderAttachmentUploadScope;
use Shopware\Core\Content\Media\Event\MediaFileExtensionWhitelistEvent;
use Shopware\Core\Content\Media\MediaException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ergänzt die Liste erlaubter Endungen des Kerns um die in der Plugin-Konfiguration freigegebenen,
 * und zwar nur für Uploads dieses Plugins.
 *
 * Trägt jemand `dwg` in die Plugin-Konfiguration ein, stiege `FileSaver::validateFileExtension`
 * ohne diesen Subscriber mit `MEDIA_FILE_EXTENSION_NOT_SUPPORTED` aus, weil `dwg` nicht in
 * Shopwares `private_allowed_extensions` steht.
 *
 * Der Kern löst das Ereignis bei jedem Medien-Upload aus und unterscheidet dabei nur zwischen
 * öffentlicher und privater Liste
 * ({@see \Shopware\Core\Content\Media\Upload\MediaFileExtensionValidator}); woher der Upload
 * stammt, verrät es nicht. Der Upload-Dienst kennzeichnet seinen Context deshalb mit
 * {@see OrderAttachmentUploadScope}, und nur dann greift die Erweiterung. Sonst würde die
 * Plugin-Konfiguration zur Konfiguration des ganzen Shops.
 *
 * Die Endungen trägt das Kennzeichen selbst, aus der Konfiguration des Kanals, in dem hochgeladen
 * wird; das Ereignis kennt keinen Kanal.
 *
 * Gefährliche Endungen kommen hier nicht an: Der PluginConfigProvider entfernt die Einträge der
 * Sperrliste aus {@see \Ruhrcoder\RcOrderAttachment\Service\Validation\DangerousContentValidator}
 * schon beim Lesen, und der Upload-Dienst hat jede Datei vor dem Speichern geprüft.
 */
final class MediaWhitelistSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            MediaFileExtensionWhitelistEvent::class => 'onWhitelist',
        ];
    }

    public function onWhitelist(MediaFileExtensionWhitelistEvent $event): void
    {
        $scope = $this->pluginUploadScope($event);
        if ($scope === null || $scope->allowedExtensions === []) {
            return;
        }

        $existing = array_map('strtolower', $event->getWhitelist());
        $merged = array_values(array_unique(array_merge($existing, $scope->allowedExtensions)));

        $event->setWhitelist($merged);
    }

    /**
     * Das Kennzeichen eines Uploads aus diesem Plugin, oder `null`.
     *
     * Lässt sich die Herkunft nicht belegen, bleibt die Liste des Kerns unverändert. Bis Shopware
     * 6.8 ist der Context im Ereignis optional, und {@see MediaFileExtensionWhitelistEvent::getContext()}
     * wirft, wenn er fehlt. Auf den Pfaden des Kerns kommt das nicht vor; das `catch` verhindert
     * nur, dass ein fremder Auslöser ohne Context jeden Medien-Upload im Shop scheitern lässt.
     */
    private function pluginUploadScope(MediaFileExtensionWhitelistEvent $event): ?OrderAttachmentUploadScope
    {
        try {
            $scope = $event->getContext()->getExtension(OrderAttachmentUploadScope::NAME);
        } catch (MediaException) {
            return null;
        }

        return $scope instanceof OrderAttachmentUploadScope ? $scope : null;
    }
}
