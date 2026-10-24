<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Exception;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ein Anhang verweist auf ein Medium, das nicht geladen werden konnte, meist weil eine der
 * Aufräumaufgaben es schon gelöscht hat.
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
