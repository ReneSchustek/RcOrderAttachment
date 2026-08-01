<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Mail;

use Shopware\Core\Framework\Context;

/**
 * Schreibt das Mail-Anhang-Status-Custom-Field auf `order`. Eigene Schnittstelle,
 * damit der Subscriber/Handler entkoppelt testbar bleibt und eine spätere
 * Audit-Decoration ohne Eingriff in die Caller eingehängt werden kann.
 */
interface MailAttachmentStatusTrackerInterface
{
    public function markAttached(string $orderId, Context $context): void;

    public function markPartialFailure(string $orderId, Context $context): void;

    public function markFailed(string $orderId, Context $context): void;
}
