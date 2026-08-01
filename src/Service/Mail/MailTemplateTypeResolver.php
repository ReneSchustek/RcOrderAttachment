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
 * Löst die `mailTemplateId` einer ausgehenden Mail zum `technicalName` ihres
 * Mail-Template-Types auf (z. B. `order_confirmation_mail`).
 *
 * Warum es diesen Service braucht:
 *   `MailBeforeValidateEvent::getTemplateData()` enthält in Shopware 6.7 KEINEN
 *   `mailTemplate`-Key — `SendMailAction` baut die Template-Daten als
 *   `['eventName' => $flow->getName(), ...$flow->data()]`. Der einzige Bezug zum
 *   konkreten Mail-Template steckt in `getData()['templateId']`. Ohne Auflösung
 *   könnte eine Whitelist nur gegen Flow-Event-Namen (`checkout.order.placed`)
 *   prüfen, nie gegen Template-Type-Namen.
 *
 * Fail-closed: Kann der Name nicht aufgelöst werden, liefert der Service `null`.
 * Der Aufrufer entscheidet dann anhand seiner übrigen Kandidaten — im Zweifel
 * wird NICHT angehängt, statt Anhänge an eine unbekannte Mail zu hängen.
 *
 * Worker-Mode: Der Lookup-Cache ist Instance-State und wird über
 * {@see ResetInterface} pro Request geleert.
 */
final class MailTemplateTypeResolver implements MailTemplateTypeResolverInterface, ResetInterface
{
    /**
     * @var array<string, string|null> Key = mailTemplateId, Value = technicalName oder null
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
            // Nur das ECHTE Ergebnis (gefunden / nicht gefunden = null) cachen.
            // Ein transienter Lookup-Fehler darf nicht als endgültiges `null`
            // eingefroren werden — sonst blieben für den Rest des Requests bzw.
            // der Worker-Nachricht alle Anhänge aus, obwohl nur ein DB-Hiccup vorlag.
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

        // Scope-Wechsel: der Mail-Versand läuft je nach Flow-Kontext auch mit
        // Sales-Channel-Scope, `mail_template` ist dort nicht lesbar. Fehler
        // propagieren bewusst an den Aufrufer, damit sie NICHT gecacht werden.
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
        $template = $this->mailTemplateRepository->search($criteria, $context)->first();

        return $template instanceof MailTemplateEntity ? $template : null;
    }
}
