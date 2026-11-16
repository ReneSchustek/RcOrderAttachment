<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service;

use League\Flysystem\FilesystemException;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Service\Exception\AttachmentNotFoundException;
use Ruhrcoder\RcOrderAttachment\Service\Exception\MediaNotLoadedException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaException;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Liefert Anhänge als Download aus, für den Kunden im Kundenkonto (Auskunft nach Art. 15 DSGVO)
 * und für Mitarbeiter in der Verwaltung.
 *
 * Für Kunden lädt {@see OrderAttachmentLoader::loadByIdForCustomer()} den Anhang nur, wenn
 * `order.orderCustomer.customerId` zum angemeldeten Kunden passt; die Controller prüfen nichts
 * selbst. Die Datei geht als Strom mit `Content-Disposition: attachment` hinaus, damit der Browser
 * sie speichert, statt sie anzuzeigen.
 */
final class OrderAttachmentDownloadService
{
    public function __construct(
        private readonly OrderAttachmentLoader $loader,
        private readonly MediaService $mediaService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function streamForCustomer(string $attachmentId, CustomerEntity $customer, Context $context): Response
    {
        $attachment = $this->loadAndAuthorize($attachmentId, $customer, $context);

        $media = $attachment->getMedia();
        if (!$media instanceof MediaEntity) {
            throw new MediaNotLoadedException($attachment->getMediaId());
        }

        return $this->streamMedia($attachment, $media, $context);
    }

    /**
     * Liefert einen Anhang an einen Mitarbeiter aus.
     *
     * Eine Prüfung auf den Besitzer entfällt, weil die aufrufende Route schon das Recht
     * `order:read` verlangt; wer Bestellungen lesen darf, darf jeden Anhang laden. Gelesen wird
     * wie beim Kunden im Systemkontext, weil das Medium privat ist.
     */
    public function streamForAdmin(string $attachmentId, Context $context): Response
    {
        $attachment = $this->loader->loadById($attachmentId, $context);

        $media = $attachment->getMedia();
        if (!$media instanceof MediaEntity) {
            throw new MediaNotLoadedException($attachment->getMediaId());
        }

        $this->logger->info('rc_order_attachment.admin_download', [
            'attachmentId' => $attachment->getId(),
            'orderId' => $attachment->getOrderId(),
        ]);

        return $this->streamMedia($attachment, $media, $context);
    }

    /**
     * Wirft {@see AttachmentNotFoundException}, wenn der Anhang nicht existiert oder nicht zu einer
     * Bestellung des Kunden gehört. Beide Fälle enden im selben 404, damit niemand durch Probieren
     * herausfindet, welche fremden Anhang-IDs es gibt.
     */
    private function loadAndAuthorize(string $attachmentId, CustomerEntity $customer, Context $context): OrderAttachmentEntity
    {
        $attachment = $this->loader->loadByIdForCustomer($attachmentId, $customer->getId(), $context);

        $this->logger->info('rc_order_attachment.customer_download', [
            'customerId' => $customer->getId(),
            'attachmentId' => $attachment->getId(),
            'orderId' => $attachment->getOrderId(),
        ]);

        return $attachment;
    }

    private function streamMedia(OrderAttachmentEntity $attachment, MediaEntity $media, Context $context): StreamedResponse
    {
        $mimeType = $attachment->getMimeType() !== '' ? $attachment->getMimeType() : 'application/octet-stream';
        $filename = $attachment->getOriginalFileName();

        // Der Strom öffnet vor der Antwort: Fehlt die Datei, sind die Kopfzeilen noch nicht gesendet, und der Aufrufer
        // kann mit 404 antworten statt mit einem leeren 200. Als Strom gelesen, damit eine große Datei nicht ganz im
        // Arbeitsspeicher landet. Der Systemkontext ist nötig, weil der Kunde das private Medium nicht direkt lesen darf.
        try {
            $stream = $context->scope(
                Context::SYSTEM_SCOPE,
                fn (Context $systemContext): StreamInterface => $this->mediaService->loadFileStream($media->getId(), $systemContext),
            );
        } catch (FilesystemException | MediaException $exception) {
            $this->logger->warning('rc_order_attachment.file_missing', [
                'attachmentId' => $attachment->getId(),
                'mediaId' => $media->getId(),
                'exception' => $exception::class,
            ]);

            throw new MediaNotLoadedException($media->getId());
        }

        $response = new StreamedResponse(static function () use ($stream): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                $stream->close();

                return;
            }
            try {
                // 8 KiB je Block ist die übliche Puffergröße von PHP-Strömen; größere Blöcke
                // sparen kaum Aufrufe, kleinere vervielfachen sie.
                while (!$stream->eof()) {
                    fwrite($output, $stream->read(8192));
                }
            } finally {
                fclose($output);
                $stream->close();
            }
        });

        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename, $this->asciiFallback($filename))
        );
        // `nosniff` hindert den Browser daran, eine als Bild gemeldete Datei doch als HTML
        // auszuführen; `no-store` hält die private Datei aus jedem Zwischenspeicher heraus.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * Der Rückfallname für `Content-Disposition`. Symfony lehnt Umlaute und `%` darin ab und nimmt ohne Angabe den echten
     * Namen, der Download von „Schrägschnitt.pdf“ endete deshalb mit 500. Den echten Namen bekommt der Browser weiter
     * über `filename*`.
     */
    private function asciiFallback(string $filename): string
    {
        $transliterated = strtr($filename, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);

        return preg_replace('/[^\x20-\x7E]|[%\/\\\\]/u', '_', $transliterated) ?? 'download';
    }
}
