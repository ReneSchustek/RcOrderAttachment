<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Service\Exception\AttachmentNotFoundException;
use Ruhrcoder\RcOrderAttachment\Service\Exception\MediaNotLoadedException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stellt Anhänge dem Customer im Account-Bereich zur Verfügung (DSGVO Art. 15
 * Auskunft + Art. 17 Löschen).
 *
 * Owner-Check: Customer darf nur Anhänge seiner eigenen Orders sehen. Die
 * Prüfung läuft gegen `Order.orderCustomer.customerId` und ist hier zentral —
 * der Controller delegiert komplett.
 *
 * Datenfluss: Customer fordert Attachment-Download an → Service lädt Anhang
 * inkl. `order.orderCustomer` und `media` → Owner-Check → Media-Stream als
 * `Content-Disposition: attachment` zurück.
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
     * Stellt einen Anhang einem Admin-Nutzer zur Verfügung (Auftragsbearbeitung).
     *
     * Kein Owner-Check: Die Autorisierung liegt hier auf der ACL des aufrufenden
     * Admin-Endpoints (`order:read`) statt auf einer Customer-Beziehung — ein
     * Mitarbeiter mit Order-Lese-Recht darf jeden Bestell-Anhang laden. Der
     * Media-Stream selbst läuft — wie im Customer-Pfad — im System-Scope, weil
     * das Media privat ist und keine direkte Read-Berechtigung trägt.
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
     * Wirft {@see AttachmentNotFoundException}, wenn:
     *   - der Anhang nicht existiert
     *   - er nicht zur Order des Customers gehört
     *
     * Beide Fälle liefern denselben HTTP-404, damit kein IDOR-Pfad existiert,
     * über den ein Angreifer fremde Anhang-IDs validieren könnte.
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

        $response = new StreamedResponse(function () use ($media, $context): void {
            // Stream-API verhindert, dass die ganze Datei in PHP-Memory landet.
            // Sicherheits-Scope-Wechsel: Customer hat keine direkte Media-Read-Berechtigung.
            $context->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use ($media): void {
                $stream = $this->mediaService->loadFileStream($media->getId(), $systemContext);
                $output = fopen('php://output', 'wb');
                if ($output === false) {
                    $stream->close();

                    return;
                }
                try {
                    while (!$stream->eof()) {
                        fwrite($output, $stream->read(8192));
                    }
                } finally {
                    fclose($output);
                    $stream->close();
                }
            });
        });

        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename)
        );
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
