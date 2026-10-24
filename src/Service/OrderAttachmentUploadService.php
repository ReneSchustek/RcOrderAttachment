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
 * Nimmt eine hochgeladene Datei aus dem Storefront-Controller entgegen, prüft sie, speichert sie
 * als privates Medium und merkt sie als {@see PendingUpload} in der Sitzung vor.
 *
 * Geschrieben wird im Systemkontext, weil ein Kunde, ob Gast oder angemeldet, auf `media` keine
 * Rechte hat. Dieser Dienst ist die einzige Stelle, die für Uploads von Kunden in den
 * Systemkontext wechselt, und damit die Vertrauensgrenze.
 *
 * Die Datei landet über {@see MediaService::saveMediaFile} im privaten Plugin-Ordner unter dem
 * neuen Namen `rc-<uuid>`. Der ursprüngliche Name steht nur als Beschriftung in der Datenbank.
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
        // Der Name wird vor allem anderen bereinigt, weil derselbe Wert ins Protokoll, in die Sitzung
        // und später in die Kopfzeilen der Mail geht. Ein Protokoll als reiner Text ohne JSON nähme
        // sonst eingeschleuste Zeilenumbrüche an.
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

        $mediaId = $this->persistFile($file, $config->allowedExtensions, $context, $logContext);
        $token = Uuid::randomHex();

        $upload = new PendingUpload(
            token: $token,
            mediaId: $mediaId,
            originalFileName: $sanitizedName,
            mimeType: $file->getMimeType() ?? $file->getClientMimeType(),
            fileSize: $file->getSize() !== false ? $file->getSize() : 0,
            uploadedAt: time(),
        );

        // Ohne Sitzung lässt sich die Datei später keiner Bestellung zuordnen. Als Erfolg gemeldet,
        // sähe der Kunde seine Datei in der Liste, obwohl sie nie an der Bestellung ankäme. Das eben
        // erzeugte Medium wird deshalb wieder gelöscht und der Fehler zurückgemeldet.
        if (!$this->pendingUploadStore->add($upload)) {
            $this->logger->error('rc_order_attachment.upload.no_session', $logContext + [
                'mediaId' => $mediaId,
                'hint' => 'Upload ohne Session -- Datei kann keiner Bestellung zugeordnet werden.',
            ]);

            $this->deleteMediaSafely($mediaId, $context, $logContext);

            $result = new ValidationResult();
            $result->fail(ValidationCode::SESSION_UNAVAILABLE);

            throw new AttachmentUploadException([ValidationCode::SESSION_UNAVAILABLE->value], $result);
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

        // Der Fremdschlüssel `fk.rc_order_attachment.media_id` löscht kaskadierend; mit dem Medium
        // verschwände auch der Anhang jeder Bestellung, die darauf zeigt. Ist das Medium schon an
        // eine Bestellung gebunden, fällt nur der Eintrag in der Sitzung weg, die Datei bleibt.
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
     * Meldet, ob das Medium über eine `rc_order_attachment`-Zeile an eine Bestellung gebunden ist.
     * Scheitert die Abfrage, lautet die Antwort `true`, damit im Zweifel nichts gelöscht wird: Ein
     * verwaistes Medium räumt die Aufräumaufgabe später ab, ein gelöschter Anhang ist verloren.
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
            // Das Neuschreiben hat die Dateigröße verändert. Ohne Leeren des Zwischenspeichers
            // liefert `UploadedFile::getSize()` über `filesize()` noch die alte Größe, und die
            // landete falsch in Medium und Sitzung.
            clearstatcache(true, $path);
            $this->logger->info('rc_order_attachment.upload.exif_stripped', $logContext);
        }
    }

    /**
     * @param list<string>         $allowedExtensions die Endungen des Kanals, mit denen die Datei geprüft wurde
     * @param array<string, mixed> $logContext
     */
    private function persistFile(UploadedFile $file, array $allowedExtensions, Context $context, array $logContext): string
    {
        $rawExtension = $file->getClientOriginalExtension();
        $extension = strtolower($rawExtension !== '' ? $rawExtension : 'bin');
        $mime = $file->getMimeType() ?? $file->getClientMimeType();
        $size = $file->getSize() !== false ? $file->getSize() : 0;
        $physicalPath = $file->getRealPath() !== false ? $file->getRealPath() : $file->getPathname();
        $internalName = 'rc-' . Uuid::randomHex();

        $mediaFile = new MediaFile($physicalPath, $mime, $extension, $size);

        return $context->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use ($mediaFile, $internalName, $allowedExtensions, $logContext): string {
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
                $allowedExtensions,
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
     * Speichert die Datei mit dem Herkunftskennzeichen am Context, damit der
     * {@see \Ruhrcoder\RcOrderAttachment\Service\Subscriber\MediaWhitelistSubscriber}
     * die Endungsliste genau für diesen Aufruf erweitert.
     *
     * Das `finally` entfernt das Kennzeichen wieder, weil der Context über die ganze Anfrage lebt.
     * Bliebe es stehen, erbte ein späterer Upload derselben Anfrage die Endungen des Plugins.
     *
     * @param list<string> $allowedExtensions die Endungen des Kanals, mit denen die Datei geprüft wurde
     */
    private function saveWithPluginWhitelist(
        MediaFile $mediaFile,
        string $internalName,
        string $mediaId,
        array $allowedExtensions,
        Context $context,
    ): string {
        $context->addExtension(OrderAttachmentUploadScope::NAME, new OrderAttachmentUploadScope($allowedExtensions));

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
            // Ein Fehler beim Löschen darf den Kunden nicht aufhalten; die Aufräumaufgabe holt es nach.
            $this->logger->warning('rc_order_attachment.upload.delete_failed', $logContext + [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
