<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Mail;

use Psr\Log\LoggerInterface;
use Shopware\Core\Content\MailTemplate\MailTemplateCollection;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Ermittelt zur `mailTemplateId` einer ausgehenden Mail den `technicalName` ihres Vorlagentyps,
 * etwa `order_confirmation_mail`.
 *
 * `MailBeforeValidateEvent::getTemplateData()` enthält in Shopware 6.7 keinen Schlüssel
 * `mailTemplate`, weil `SendMailAction` die Vorlagendaten als
 * `['eventName' => $flow->getName(), ...$flow->data()]` baut. Der einzige Bezug zur konkreten
 * Vorlage steckt in `getData()['templateId']`. Ohne diese Auflösung ließe sich die Liste erlaubter
 * Vorlagen nur gegen Ereignisnamen wie `checkout.order.placed` prüfen.
 *
 * Lässt sich der Name nicht ermitteln, liefert der Dienst `null`. Der Aufrufer entscheidet dann
 * anhand seiner übrigen Kandidaten und hängt im Zweifel nichts an.
 *
 * Der Zwischenspeicher lebt in der Instanz. Im Worker-Betrieb leert ihn {@see ResetInterface}
 * nach jeder Anfrage, damit keine Zuordnung über Anfragen hinweg stehen bleibt.
 */
final class MailTemplateTypeResolver implements MailTemplateTypeResolverInterface, ResetInterface
{
    /**
     * @var array<string, string|null> Schlüssel ist die mailTemplateId, Wert der technicalName oder null
     */
    private array $cache = [];

    /**
     * @param EntityRepository<MailTemplateCollection> $mailTemplateRepository
     */
    public function __construct(
        private readonly EntityRepository $mailTemplateRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolveTechnicalName(?string $mailTemplateId, Context $context): ?string
    {
        if ($mailTemplateId === null || !Uuid::isValid($mailTemplateId)) {
            return null;
        }

        if (\array_key_exists($mailTemplateId, $this->cache)) {
            return $this->cache[$mailTemplateId];
        }

        try {
            // Gespeichert wird nur ein echtes Ergebnis, auch „nicht gefunden". Ein vorübergehender
            // Fehler darf nicht als `null` stehen bleiben, sonst fehlten für den Rest der Anfrage
            // oder der Worker-Nachricht alle Anhänge, obwohl die Datenbank nur kurz hakte.
            return $this->cache[$mailTemplateId] = $this->lookup($mailTemplateId, $context);
        } catch (Throwable $exception) {
            $this->logger->warning('rc_order_attachment.mail.template_type_lookup_failed', [
                'mailTemplateId' => $mailTemplateId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    private function lookup(string $mailTemplateId, Context $context): ?string
    {
        $criteria = new Criteria([$mailTemplateId]);
        $criteria->addAssociation('mailTemplateType');
        $criteria->setLimit(1);

        // Je nach Flow läuft der Mailversand im Verkaufskanal-Kontext, in dem `mail_template` nicht
        // lesbar ist. Fehler laufen ungefangen zum Aufrufer durch, damit sie nicht im
        // Zwischenspeicher landen.
        $template = $context->scope(
            Context::SYSTEM_SCOPE,
            fn (Context $systemContext): ?MailTemplateEntity => $this->firstTemplate($criteria, $systemContext),
        );

        if (!$template instanceof MailTemplateEntity) {
            return null;
        }

        $technicalName = $template->getMailTemplateType()?->getTechnicalName();

        return $technicalName !== null && $technicalName !== '' ? $technicalName : null;
    }

    private function firstTemplate(Criteria $criteria, Context $context): ?MailTemplateEntity
    {
        $template = $this->mailTemplateRepository->search($criteria, $context)->getEntities()->first();

        return $template instanceof MailTemplateEntity ? $template : null;
    }
}
