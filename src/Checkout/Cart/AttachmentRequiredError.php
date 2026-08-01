<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * Cart-Level-Fehler, der ausgelöst wird, wenn das Plugin auf „Upload Pflicht"
 * konfiguriert ist, der Customer aber noch keine Datei hochgeladen hat.
 *
 * `blockOrder() = true` verhindert auf Server-Seite das Order-Placement —
 * selbst wenn das JS-Plugin im Browser deaktiviert oder umgangen wurde.
 */
final class AttachmentRequiredError extends Error
{
    private const KEY = 'rc-order-attachment-required';

    public function __construct()
    {
        parent::__construct('Mindestens ein Dokument muss hochgeladen werden, bevor die Bestellung abgeschlossen werden kann.');
    }

    public function getId(): string
    {
        return self::KEY;
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    public function getParameters(): array
    {
        return [];
    }
}
