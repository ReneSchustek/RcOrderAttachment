<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Exception;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 404 für fehlende Anhänge.
 *
 * Wird auch als „Owner-Check fehlgeschlagen" geworfen — dieselbe Antwortzeit
 * für „nicht vorhanden" und „nicht autorisiert" verhindert IDOR-Disclosure
 * über die Antwortdauer (siehe `OrderAttachmentLoader::loadByIdForCustomer`).
 */
final class AttachmentNotFoundException extends HttpException
{
    public const ERROR_CODE = 'RC_ORDER_ATTACHMENT__NOT_FOUND';

    public function __construct(string $attachmentId)
    {
        parent::__construct(
            Response::HTTP_NOT_FOUND,
            self::ERROR_CODE,
            'Anhang {{ attachmentId }} wurde nicht gefunden.',
            ['attachmentId' => $attachmentId],
        );
    }
}
