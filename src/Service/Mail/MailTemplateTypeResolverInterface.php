<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Mail;

use Shopware\Core\Framework\Context;

/**
 * Löst eine `mailTemplateId` zum `technicalName` ihres Mail-Template-Types auf.
 * Eigene Schnittstelle, damit der Mail-Subscriber ohne DAL-Boundary testbar
 * bleibt und eine Caching-Decoration ohne Eingriff in den Caller möglich ist.
 */
interface MailTemplateTypeResolverInterface
{
    /**
     * Liefert `null`, wenn die ID fehlt, ungültig ist, kein Template existiert
     * oder der Lookup fehlschlägt (fail-closed).
     */
    public function resolveTechnicalName(?string $mailTemplateId, Context $context): ?string;
}
