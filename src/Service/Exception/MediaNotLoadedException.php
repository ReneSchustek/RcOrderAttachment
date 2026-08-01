<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Exception;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wird geworfen, wenn ein erwartetes Media-Objekt nicht (mehr) in der DAL
 * vorhanden oder nicht geladen ist. Tritt typischerweise auf, wenn ein
 * Anhang-Datensatz auf eine inzwischen gelöschte Media-Datei verweist.
 */
final class MediaNotLoadedException extends HttpException
{
    public const ERROR_CODE = 'RC_ORDER_ATTACHMENT__MEDIA_NOT_LOADED';

    public function __construct(string $mediaId)
    {
        parent::__construct(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            self::ERROR_CODE,
            'Media {{ mediaId }} ist nicht geladen oder nicht verfügbar.',
            ['mediaId' => $mediaId],
        );
    }
}
