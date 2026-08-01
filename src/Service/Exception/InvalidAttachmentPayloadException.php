<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Exception;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 400 für Programmier- oder Konfigurationsfehler im Aufrufpfad (z. B. fehlende
 * UUID, negativer Wert). Wird im Storefront-Pfad nicht für Customer-Fehler
 * benutzt — Customer-Fehler laufen über {@see AttachmentUploadException}
 * (422 mit Validierungs-Codes).
 */
final class InvalidAttachmentPayloadException extends HttpException
{
    public const ERROR_CODE = 'RC_ORDER_ATTACHMENT__INVALID_PAYLOAD';

    public function __construct(string $field, string $reason)
    {
        parent::__construct(
            Response::HTTP_BAD_REQUEST,
            self::ERROR_CODE,
            'Ungültige Eingabe für Feld {{ field }}: {{ reason }}.',
            ['field' => $field, 'reason' => $reason],
        );
    }
}
