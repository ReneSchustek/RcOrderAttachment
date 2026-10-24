<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Mail;

use Shopware\Core\Framework\Context;

/**
 * Schreibt den Anhangstatus der Bestellbestätigung an die Bestellung. Die Schnittstelle trennt
 * Subscriber und Handler vom Speichern, damit Tests sie ohne Datenbank prüfen und ein Dekorator
 * etwa für ein Prüfprotokoll eingehängt werden kann, ohne die Aufrufer anzufassen.
 */
interface MailAttachmentStatusTrackerInterface
{
    public function markAttached(string $orderId, Context $context): void;

    public function markPartialFailure(string $orderId, Context $context): void;

    public function markFailed(string $orderId, Context $context): void;
}
