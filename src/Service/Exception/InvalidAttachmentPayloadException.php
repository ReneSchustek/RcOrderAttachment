<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Exception;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 400 für Programmier- oder Konfigurationsfehler im Aufrufpfad, etwa eine ungültige UUID oder
 * eine negative Größe.
 *
 * Für Fehler, die der Kunde selbst beheben kann, gibt es {@see AttachmentUploadException} mit
 * 422 und Prüfcodes. Einzige Ausnahme ist ein Dateiname, der nach der Bereinigung leer ist.
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
