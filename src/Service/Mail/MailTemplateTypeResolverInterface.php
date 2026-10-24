<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Mail;

use Shopware\Core\Framework\Context;

/**
 * Ermittelt zu einer `mailTemplateId` den `technicalName` ihres Vorlagentyps. Die Schnittstelle
 * lässt den Mail-Subscriber ohne Datenbank testen und erlaubt einen Dekorator, ohne den Aufrufer
 * anzufassen.
 */
interface MailTemplateTypeResolverInterface
{
    /**
     * Liefert `null`, wenn die ID fehlt oder ungültig ist, keine Vorlage existiert oder die Abfrage
     * scheitert. Der Aufrufer hängt in diesem Fall im Zweifel nichts an.
     */
    public function resolveTechnicalName(?string $mailTemplateId, Context $context): ?string;
}
