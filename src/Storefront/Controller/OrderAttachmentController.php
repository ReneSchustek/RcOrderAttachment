<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\Exception\AttachmentNotFoundException;
use Ruhrcoder\RcOrderAttachment\Service\Exception\AttachmentUploadException;
use Ruhrcoder\RcOrderAttachment\Service\Exception\InvalidAttachmentPayloadException;
use Ruhrcoder\RcOrderAttachment\Service\Exception\MediaNotLoadedException;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentDownloadService;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentUploadService;
use Ruhrcoder\RcOrderAttachment\Service\RateLimit\UploadRateLimiter;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUploadStore;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Framework\Routing\StorefrontRouteScope;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Storefront-Routen für die Uploads der Kunden im Checkout und den Download im Kundenkonto.
 *
 * `POST /rc-order-attachment/upload` nimmt eine Datei im Feld `file` entgegen, von der
 * Bestätigungsseite und auch für Gäste. `POST /rc-order-attachment/{token}` entfernt einen noch
 * nicht verknüpften Upload; es ist POST statt DELETE, weil die Storefront Zustandsänderungen über
 * POST abwickelt. `GET /account/order-attachment/{attachmentId}/download` liefert dem angemeldeten
 * Kunden seinen Anhang aus dem Kundenkonto (Auskunft nach Art. 15 DSGVO); Gäste haben dort keinen
 * Zugang.
 *
 * Eine eigene CSRF-Prüfung gibt es nicht, weil Shopware 6.7 keinen CSRF-Layer hat
 * (`sw_csrf` und `CsrfPlaceholderHandler` fehlen). Den Schutz trägt das
 * Sitzungscookie mit `SameSite=Lax`: Eine POST-Anfrage von fremder Seite kommt ohne Sitzung an
 * und trifft auf eine leere Upload-Liste. Alle ändernden Routen nehmen nur POST an, und die Tokens
 * sind UUIDv7 aus `Uuid::randomHex()` mit 74 Bit Zufall neben dem Zeitstempel, gebunden an die Sitzung.
 */
#[Route(defaults: [
    PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [StorefrontRouteScope::ID],
    PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
    PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED_ALLOW_GUEST => true,
])]
final class OrderAttachmentController extends StorefrontController
{
    public function __construct(
        private readonly OrderAttachmentUploadService $uploadService,
        private readonly OrderAttachmentDownloadService $downloadService,
        private readonly PendingUploadStore $pendingUploadStore,
        private readonly PluginConfigProvider $configProvider,
        private readonly UploadRateLimiter $rateLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/rc-order-attachment/upload',
        name: 'frontend.rc-order-attachment.upload',
        defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true],
        methods: [Request::METHOD_POST],
    )]
    public function upload(Request $request, SalesChannelContext $context): JsonResponse
    {
        $config = $this->configProvider->getForSalesChannel($context->getSalesChannelId());
        if (!$config->enabled) {
            return $this->errorResponse(['pluginDisabled'], Response::HTTP_FORBIDDEN);
        }

        // Ohne Sitzung zählt die Grenze je IP-Adresse; hinter einem gemeinsamen Ausgang teilen sich
        // dann mehrere Kunden einen Zähler.
        $sessionId = $request->hasSession() ? $request->getSession()->getId() : ($request->getClientIp() ?? '');
        if (!$this->rateLimiter->consume($sessionId, $config)) {
            return $this->errorResponse(['rateLimitExceeded'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->errorResponse(['emptyUpload'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $upload = $this->uploadService->upload($file, $config, $context->getContext());
        } catch (AttachmentUploadException $exception) {
            return $this->errorResponse($exception->codes, Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InvalidAttachmentPayloadException $exception) {
            // Ausgelöst vom Kunden, etwa durch einen Dateinamen, der nach der Bereinigung leer ist.
            // Das ist ein Eingabefehler (422), kein Serverfehler (500).
            return $this->errorResponse(['uploadError'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $exception) {
            $this->logger->error('rc_order_attachment.controller.unexpected_failure', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse(['uploadError'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->secureJsonResponse([
            'success' => true,
            'upload' => [
                'token' => $upload->token,
                'name' => $upload->originalFileName,
                'sizeBytes' => $upload->fileSize,
                'mimeType' => $upload->mimeType,
            ],
            'remaining' => [
                'files' => max(0, $config->maxFiles - $this->pendingUploadStore->count()),
                'bytes' => max(0, $config->maxTotalSizeBytes() - $this->pendingUploadStore->totalBytes()),
            ],
        ]);
    }

    #[Route(
        path: '/rc-order-attachment/{token}',
        name: 'frontend.rc-order-attachment.remove',
        defaults: [PlatformRequest::ATTRIBUTE_NO_STORE => true],
        requirements: ['token' => '[a-f0-9]{32}'],
        methods: [Request::METHOD_POST],
    )]
    public function remove(string $token, SalesChannelContext $context): JsonResponse
    {
        // Gesucht wird nur in der eigenen Sitzung. Ein fremder Token findet dort nichts, und der
        // Aufruf bleibt ohne Wirkung; die Datei einer anderen Sitzung lässt sich so nicht löschen.
        $this->uploadService->remove($token, $context->getContext());

        $config = $this->configProvider->getForSalesChannel($context->getSalesChannelId());

        return $this->secureJsonResponse([
            'success' => true,
            'remaining' => [
                'files' => max(0, $config->maxFiles - $this->pendingUploadStore->count()),
                'bytes' => max(0, $config->maxTotalSizeBytes() - $this->pendingUploadStore->totalBytes()),
            ],
        ]);
    }

    #[Route(
        path: '/account/order-attachment/{attachmentId}/download',
        name: 'frontend.rc-order-attachment.account-download',
        defaults: [
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED => true,
            PlatformRequest::ATTRIBUTE_LOGIN_REQUIRED_ALLOW_GUEST => false,
            PlatformRequest::ATTRIBUTE_NO_STORE => true,
        ],
        requirements: ['attachmentId' => '[a-f0-9]{32}'],
        methods: [Request::METHOD_GET],
    )]
    public function accountDownload(string $attachmentId, SalesChannelContext $context): Response
    {
        // Die Route sperrt Gäste schon über ihre Vorgaben. Die Prüfung hier deckt den Fall ab, dass
        // ein Gast trotzdem bis in die Methode kommt, und gibt dann 403 statt einer Umleitung.
        $customer = $context->getCustomer();
        if (!$customer instanceof CustomerEntity || $customer->getGuest()) {
            return $this->secureJsonResponse(['success' => false, 'errors' => ['unauthorized']], Response::HTTP_FORBIDDEN);
        }

        try {
            return $this->downloadService->streamForCustomer($attachmentId, $customer, $context->getContext());
        } catch (AttachmentNotFoundException | MediaNotLoadedException) {
            // Auch ein Anhang, dessen Datei schon gelöscht ist (Aufbewahrungsfrist), ist für den Kunden
            // nicht da; ein 500 meldete einen Serverfehler, wo nichts kaputt ist. Wie im Admin-Pfad.
            return $this->secureJsonResponse(['success' => false, 'errors' => ['notFound']], Response::HTTP_NOT_FOUND);
        } catch (Throwable $exception) {
            $this->logger->error('rc_order_attachment.account_download.failed', [
                'attachmentId' => $attachmentId,
                'customerId' => $customer->getId(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->secureJsonResponse(['success' => false, 'errors' => ['downloadError']], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @param list<string> $codes
     */
    private function errorResponse(array $codes, int $status): JsonResponse
    {
        return $this->secureJsonResponse([
            'success' => false,
            'errors' => array_values($codes),
        ], $status);
    }

    /**
     * Baut jede JSON-Antwort dieses Controllers mit den Kopfzeilen, die MIME-Raten, Einbetten in
     * Rahmen und Skriptausführung verhindern. Alle Routen nutzen sie, damit keine Antwort ohne
     * diese Kopfzeilen hinausgeht.
     *
     * @param array<string, mixed> $data
     */
    private function secureJsonResponse(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
