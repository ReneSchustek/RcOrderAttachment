<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Exception;

use Ruhrcoder\RcOrderAttachment\Service\Validation\ValidationResult;
use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wird vom Upload-Service geworfen, sobald die Validierungs-Pipeline mindestens
 * einen Reject geliefert hat. Die Reject-Codes werden 1:1 in die JSON-Antwort
 * des Storefront-Controllers übernommen.
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
