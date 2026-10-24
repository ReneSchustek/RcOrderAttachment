<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Administration\Controller;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Service\Exception\AttachmentNotFoundException;
use Ruhrcoder\RcOrderAttachment\Service\Exception\MediaNotLoadedException;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentDownloadService;
use Shopware\Core\Framework\Context;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Liefert den Anhang einer Bestellung über die Admin-API aus, damit ein Mitarbeiter das vom Kunden
 * im Checkout hochgeladene Dokument direkt aus der Bestellansicht herunterladen kann. Die Datei
 * liegt als privates Medium vor und ist über den Medienbrowser nicht erreichbar.
 *
 * Die Route verlangt nur das Kernrecht `order:read` und kein eigenes Recht: Wer die Bestellung
 * sehen darf, darf auch ihren Anhang sehen. Der Dienst liest das private Medium im Systemkontext,
 * weil es keine eigene Leseberechtigung trägt. Die Zugangskontrolle sitzt damit allein an dieser Route.
 */
#[Route(defaults: [PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => ['api']])]
final class OrderAttachmentAdminController
{
    public function __construct(
        private readonly OrderAttachmentDownloadService $downloadService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/api/_action/rc-order-attachment/{attachmentId}/download',
        name: 'api.action.rc-order-attachment.download',
        defaults: ['_acl' => ['order:read']],
        requirements: ['attachmentId' => '[0-9a-f]{32}'],
        methods: [Request::METHOD_GET],
    )]
    public function download(string $attachmentId, Context $context): Response
    {
        try {
            return $this->downloadService->streamForAdmin($attachmentId, $context);
        } catch (AttachmentNotFoundException | MediaNotLoadedException) {
            // Fehlt der Datensatz oder hat eine der beiden Aufräumaufgaben das Medium schon gelöscht,
            // antwortet die Route in beiden Fällen gleich mit 404. So verrät sie nicht, welche
            // Datensätze es gibt.
            return $this->errorResponse(
                Response::HTTP_NOT_FOUND,
                'RC_ORDER_ATTACHMENT__NOT_FOUND',
                'Attachment not found.',
            );
        } catch (Throwable $exception) {
            $this->logger->error('rc_order_attachment.admin_download.failed', [
                'attachmentId' => $attachmentId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'RC_ORDER_ATTACHMENT__DOWNLOAD_FAILED',
                'Attachment could not be downloaded.',
            );
        }
    }

    private function errorResponse(int $status, string $code, string $detail): JsonResponse
    {
        return new JsonResponse([
            'errors' => [[
                'status' => (string) $status,
                'code' => $code,
                'title' => Response::$statusTexts[$status] ?? 'Error',
                'detail' => $detail,
            ]],
        ], $status);
    }
}
