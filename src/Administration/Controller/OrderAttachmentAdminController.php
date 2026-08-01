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
 * Admin-API-Endpoint für den Anhang-Download an der Bestellung.
 *
 * Zweck: Ein Mitarbeiter mit Order-Lese-Recht kann das vom Customer im Checkout
 * hochgeladene Dokument direkt aus der Admin-Bestellung herunterladen, ohne den
 * Umweg über die Bestätigungs-Mail. Die Datei ist privates Media und über den
 * öffentlichen Medien-Browser nicht erreichbar.
 *
 * Autorisierung: `_acl: ['order:read']` — kein eigenes ACL-Recht, sondern das
 * Core-Order-Lese-Recht. Der eigentliche Media-Stream läuft im Service im
 * System-Scope, weil das private Media keine direkte Read-Berechtigung trägt;
 * die Zugangskontrolle sitzt damit ausschließlich an dieser Route.
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
            // Anhang existiert nicht (mehr) oder das Media wurde bereits vom
            // Retention-/Orphan-Cleanup entfernt: derselbe 404 für beide Fälle,
            // damit kein Rückschluss auf die Existenz einzelner Datensätze möglich ist.
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
