<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Installer\MediaFolderInstaller;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Ruhrcoder\RcOrderAttachment\Service\Exception\AttachmentUploadException;
use Ruhrcoder\RcOrderAttachment\Service\Filename\FilenameSanitizer;
use Ruhrcoder\RcOrderAttachment\Service\Image\ExifStripper;
use Ruhrcoder\RcOrderAttachment\Service\Media\OrderAttachmentUploadScope;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUpload;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUploadStore;
use Ruhrcoder\RcOrderAttachment\Service\Validation\UploadValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ValidationCode;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ValidationResult;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Nimmt einen `UploadedFile` aus dem Storefront-Controller, validiert, speichert
 * privat in Shopware-Media und legt einen {@see PendingUpload} in der Session ab.
 *
 * Schreibvorgänge laufen unter `Context::SYSTEM_SCOPE`, weil der Customer (Guest
 * oder eingeloggt) keine direkte DAL-Berechtigung auf `media` hat. Der Scope-Wechsel
 * ist die explizite Vertrauensgrenze — der Service ist die einzige Stelle, die ihn
 * für Customer-Uploads nutzt.
 *
 * Physische Datei landet via {@see MediaService::saveMediaFile} unter dem privaten
 * Plugin-MediaFolder. Der Dateiname auf dem Filesystem wird komplett neu vergeben
 * (`rc-<uuid>`) — der Original-Filename steht ausschließlich in der DB als Label.
 */
final class OrderAttachmentUploadService
{
    /**
     * @param EntityRepository<MediaCollection>           $mediaRepository
     * @param EntityRepository<OrderAttachmentCollection> $attachmentRepository
     */
    public function __construct(
        private readonly UploadValidator $validator,
        private readonly MediaService $mediaService,
        private readonly EntityRepository $mediaRepository,
        private readonly EntityRepository $attachmentRepository,
        private readonly MediaFolderInstaller $mediaFolderInstaller,
        private readonly PendingUploadStore $pendingUploadStore,
        private readonly FilenameSanitizer $filenameSanitizer,
        private readonly ExifStripper $exifStripper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function upload(UploadedFile $file, PluginConfig $config, Context $context): PendingUpload
    {
        // Filename früh sanitisieren — derselbe Wert geht in Log-Context, Pending-Upload
        // und später in Mail-Header. Defense-in-Depth gegen Log-Injection bei
        // textbasierten Log-Handlern, die kein JSON-Encoding machen.
        $sanitizedName = $this->filenameSanitizer->sanitize($file->getClientOriginalName());

        $correlationId = Uuid::randomHex();
        $logContext = [
            'correlationId' => $correlationId,
            'originalName' => $sanitizedName,
            'clientMime' => $file->getClientMimeType(),
            'sizeBytes' => $file->getSize() !== false ? $file->getSize() : 0,
        ];

        $this->logger->info('rc_order_attachment.upload.start', $logContext);

        $result = $this->validator->validate(
            $file,
            $config,
            $this->pendingUploadStore->count(),
            $this->pendingUploadStore->totalBytes(),
        );

        if (!$result->isValid()) {
            $codes = $result->getCodeValues();
            $this->logger->warning('rc_order_attachment.upload.rejected', $logContext + [
                'codes' => $codes,
            ]);

            throw new AttachmentUploadException($codes, $result);
        }

        $this->stripMetadataIfEnabled($file, $config, $logContext);

        $mediaId = $this->persistFile($file, $context, $logContext);
        $token = Uuid::randomHex();

        // Filename wurde oben einmalig sanitisiert — alle Konsumenten (Session,
        // Mail-Header, Logging, DB) sehen denselben, bereinigten Wert.
        $upload = new PendingUpload(
            token: $token,
            mediaId: $mediaId,
            originalFileName: $sanitizedName,
            mimeType: $file->getMimeType() ?? $file->getClientMimeType(),
            fileSize: $file->getSize() !== false ? $file->getSize() : 0,
            uploadedAt: time(),
        );

        // Fail-closed: Ohne Session kann die Datei später keiner Bestellung zugeordnet werden.
        // Vorher lief der Upload trotzdem als Erfolg durch -- der Kunde sah seine Datei in der
        // Liste, sie wäre aber nie an der Bestellung angekommen und nach Ablauf der Frist vom
        // Orphan-Cleanup entfernt worden. Jetzt wird das eben erzeugte Medium wieder gelöscht
        // und der Fehler zurückgemeldet.
        if (!$this->pendingUploadStore->add($upload)) {
            $this->logger->error('rc_order_attachment.upload.no_session', $logContext + [
                'mediaId' => $mediaId,
                'hinweis' => 'Upload ohne Session -- Datei kann keiner Bestellung zugeordnet werden.',
            ]);

            $this->deleteMediaSafely($mediaId, $context, $logContext);

            $ergebnis = new ValidationResult();
            $ergebnis->fail(ValidationCode::SESSION_UNAVAILABLE);

            throw new AttachmentUploadException([ValidationCode::SESSION_UNAVAILABLE->value], $ergebnis);
        }

        $this->logger->info('rc_order_attachment.upload.success', $logContext + [
            'mediaId' => $mediaId,
            'token' => $token,
        ]);

        return $upload;
    }

    public function remove(string $token, Context $context): void
    {
        $upload = $this->pendingUploadStore->remove($token);
        if ($upload === null) {
            return;
        }

        // Schutz gegen Datenverlust an abgeschlossenen Bestellungen: Der Fremdschlüssel
        // `fk.rc_order_attachment.media_id` steht auf `ON DELETE CASCADE`. Ein Media-Delete
        // würde den Anhang jeder Order mitreissen, die darauf zeigt. Sobald das Media an
        // eine Bestellung gebunden ist, gehört es nicht mehr dem Pending-Store — der
        // Session-Eintrag verschwindet, die Datei bleibt.
        if ($this->isBoundToOrder($upload->mediaId, $context)) {
            $this->logger->warning('rc_order_attachment.upload.remove_skipped_bound_media', [
                'token' => $token,
                'mediaId' => $upload->mediaId,
            ]);

            return;
        }

        $this->deleteMediaSafely($upload->mediaId, $context, [
            'token' => $token,
            'mediaId' => $upload->mediaId,
        ]);

        $this->logger->info('rc_order_attachment.upload.removed', [
            'token' => $token,
            'mediaId' => $upload->mediaId,
        ]);
    }

    /**
     * Prüft, ob das Media bereits über einen `rc_order_attachment`-Datensatz an eine
     * Bestellung gebunden ist. Fail-closed: Bei einem DAL-Fehler wird `true` gemeldet,
     * damit im Zweifel nichts gelöscht wird — ein verwaistes Media räumt der
     * Orphan-Cleanup-Cron ab, ein zerstörter Bestell-Anhang ist unwiederbringlich.
     */
    private function isBoundToOrder(string $mediaId, Context $context): bool
    {
        if (!Uuid::isValid($mediaId)) {
            return false;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mediaId', $mediaId));
        $criteria->setLimit(1);

        try {
            $ids = $context->scope(
                Context::SYSTEM_SCOPE,
                fn (Context $systemContext): int => $this->attachmentRepository
                    ->searchIds($criteria, $systemContext)
                    ->getTotal(),
            );
        } catch (Throwable $exception) {
            $this->logger->warning('rc_order_attachment.upload.bound_check_failed', [
                'mediaId' => $mediaId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return true;
        }

        return $ids > 0;
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private function stripMetadataIfEnabled(UploadedFile $file, PluginConfig $config, array $logContext): void
    {
        if (!$config->stripExifFromImages) {
            return;
        }

        $path = $file->getRealPath();
        if ($path === false) {
            return;
        }

        $stripped = $this->exifStripper->stripInPlace($path, strtolower($file->getClientOriginalExtension()));
        if ($stripped) {
            // Der Re-Encode hat die Datei-Größe verändert. Ohne Invalidierung
            // liefert `UploadedFile::getSize()` (über `filesize()`) den gecachten
            // Pre-Strip-Wert — der dann falsch in Media/PendingUpload landet.
            clearstatcache(true, $path);
            $this->logger->info('rc_order_attachment.upload.exif_stripped', $logContext);
        }
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private function persistFile(UploadedFile $file, Context $context, array $logContext): string
    {
        $rawExtension = $file->getClientOriginalExtension();
        $extension = strtolower($rawExtension !== '' ? $rawExtension : 'bin');
        $mime = $file->getMimeType() ?? $file->getClientMimeType();
        $size = $file->getSize() !== false ? $file->getSize() : 0;
        $physicalPath = $file->getRealPath() !== false ? $file->getRealPath() : $file->getPathname();
        $internalName = 'rc-' . Uuid::randomHex();

        $mediaFile = new MediaFile($physicalPath, $mime, $extension, $size);

        return $context->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use ($mediaFile, $internalName, $logContext): string {
            $folderId = $this->mediaFolderInstaller->findFolderId($systemContext)
                ?? $this->mediaFolderInstaller->ensureFolder($systemContext);

            $mediaId = Uuid::randomHex();
            $this->mediaRepository->create([[
                'id' => $mediaId,
                'private' => true,
                'mediaFolderId' => $folderId,
            ]], $systemContext);

            $persistedMediaId = $this->saveWithPluginWhitelist(
                $mediaFile,
                $internalName,
                $mediaId,
                $systemContext,
            );

            $this->logger->debug('rc_order_attachment.upload.persisted', $logContext + [
                'mediaId' => $persistedMediaId,
                'internalName' => $internalName,
            ]);

            return $persistedMediaId;
        });
    }

    /**
     * Speichert die Datei mit dem Herkunfts-Marker am Context, damit der
     * {@see \Ruhrcoder\RcOrderAttachment\Service\Subscriber\MediaWhitelistSubscriber}
     * die Endungs-Whitelist genau für diesen Aufruf erweitert.
     *
     * Der Marker wird im `finally` wieder entfernt: Der Context lebt über den
     * ganzen Request. Bliebe er stehen, würde ein späterer Media-Upload desselben
     * Requests die Plugin-Endungen erneut erben — genau die shopweite Ausweitung,
     * die der Marker verhindern soll.
     */
    private function saveWithPluginWhitelist(
        MediaFile $mediaFile,
        string $internalName,
        string $mediaId,
        Context $context,
    ): string {
        $context->addExtension(OrderAttachmentUploadScope::NAME, new OrderAttachmentUploadScope());

        try {
            return $this->mediaService->saveMediaFile(
                mediaFile: $mediaFile,
                filename: $internalName,
                context: $context,
                folder: null,
                mediaId: $mediaId,
                private: true,
            );
        } finally {
            $context->removeExtension(OrderAttachmentUploadScope::NAME);
        }
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private function deleteMediaSafely(string $mediaId, Context $context, array $logContext): void
    {
        if (!Uuid::isValid($mediaId)) {
            return;
        }

        try {
            $context->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use ($mediaId): void {
                $this->mediaRepository->delete([['id' => $mediaId]], $systemContext);
            });
        } catch (Throwable $exception) {
            // Delete-Fehler dürfen den Customer-Pfad nicht brechen — der Orphan-Cleanup-Cron räumt später nach.
            $this->logger->warning('rc_order_attachment.upload.delete_failed', $logContext + [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
