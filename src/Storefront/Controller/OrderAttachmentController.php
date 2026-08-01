<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\Exception\AttachmentNotFoundException;
use Ruhrcoder\RcOrderAttachment\Service\Exception\AttachmentUploadException;
use Ruhrcoder\RcOrderAttachment\Service\Exception\InvalidAttachmentPayloadException;
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
 * Storefront-Endpoints für Customer-Datei-Uploads im Checkout.
 *
 * - POST `/rc-order-attachment/upload` — multipart/form-data, Feld `file`
 *   (Confirm-Page-Upload, Login + Guest erlaubt)
 * - POST `/rc-order-attachment/{token}` — Pending-Upload vor Order-Abschluss entfernen
 *   (POST statt DELETE — Shopware routet Storefront-State-Changes über POST)
 * - GET `/account/order-attachment/{attachmentId}/download` — DSGVO Art. 15 Auskunft
 *   (Customer lädt seinen Anhang aus dem Kundenkonto, Owner-Check via DAL, Guest blockiert)
 *
 * Confirm-Page-Endpoints erlauben Guest-Sessions (Standard-Shopware-Verhalten).
 * Der Account-Download-Endpoint erfordert dagegen einen vollwertigen Customer-Account.
 *
 * CSRF: Shopware hat den CSRF-Layer mit 6.5 ersatzlos entfernt (`sw_csrf` und
 * `CsrfPlaceholderHandler` existieren nicht mehr). Der Schutz gegen
 * Cross-Site-Requests liegt seitdem beim `SameSite=Lax`-Session-Cookie: ein
 * Cross-Site-POST bekommt die Session nicht mitgeschickt und läuft damit gegen
 * einen leeren PendingUploadStore. Zusätzlich sind alle mutierenden Endpoints
 * POST-only und die Tokens sind Session-gebunden (nicht erratbar, 128 Bit).
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
            // Customer-getriggert (z. B. ein Dateiname, der nach Sanitisierung leer
            // ist): ein Eingabe-Fehler (422), kein Server-Fehler (500).
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
        // Der Token ist ein 128-Bit-Zufallswert aus der eigenen Session. Ein
        // fremder Token trifft im PendingUploadStore auf `null` und der Aufruf
        // ist ein No-Op — kein Cross-Session-Löschen möglich.
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
        // Guest-Customer haben kein Kundenkonto im klassischen Sinne — Download
        // ist nur für eingeloggte Customer mit Customer-Account.
        $customer = $context->getCustomer();
        if (!$customer instanceof CustomerEntity || $customer->getGuest()) {
            return $this->secureJsonResponse(['success' => false, 'errors' => ['unauthorized']], Response::HTTP_FORBIDDEN);
        }

        try {
            return $this->downloadService->streamForCustomer($attachmentId, $customer, $context->getContext());
        } catch (AttachmentNotFoundException $exception) {
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
     * Bündelt die JSON-Response mit den Pflicht-Browser-Headern, die MIME-Sniffing,
     * Framing und Inline-Script-Execution verhindern. Wird auf alle Endpoint-Antworten
     * angewendet, damit kein Pfad versehentlich ohne Hardening rausgeht.
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
