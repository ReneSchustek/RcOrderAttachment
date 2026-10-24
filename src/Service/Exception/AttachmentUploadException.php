<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Exception;

use Ruhrcoder\RcOrderAttachment\Service\Validation\ValidationResult;
use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ablehnung eines Uploads, sobald die Prüfkette mindestens einen Befund geliefert hat.
 *
 * Die Codes gehen unverändert in die JSON-Antwort des Storefront-Controllers, und das Skript
 * im Browser sucht zu jedem Code den passenden Text.
 */
final class AttachmentUploadException extends HttpException
{
    public const ERROR_CODE = 'RC_ORDER_ATTACHMENT__UPLOAD_REJECTED';

    /**
     * @param list<string> $codes
     */
    public function __construct(public readonly array $codes, public readonly ValidationResult $result)
    {
        parent::__construct(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            self::ERROR_CODE,
            'Upload wurde abgelehnt: {{ codes }}.',
            ['codes' => implode(', ', $codes)],
        );
    }
}
