<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Mail;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Service\Mail\MailTemplateTypeResolver;
use RuntimeException;
use Shopware\Core\Content\MailTemplate\Aggregate\MailTemplateType\MailTemplateTypeEntity;
use Shopware\Core\Content\MailTemplate\MailTemplateCollection;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * Unit-Tests für die Auflösung `mailTemplateId` → Mail-Template-Type-`technicalName`.
 *
 * Pattern: echte Resolver-Instanz, gemockte Shopware-Boundary (`EntityRepository`).
 * Der Resolver ist die einzige Stelle, die weiß, dass Shopware im
 * `MailBeforeValidateEvent` nur eine `templateId` liefert — der Rest des Plugins
 * arbeitet mit dem technischen Namen.
 */
final class MailTemplateTypeResolverTest extends TestCase
{
    private const TEMPLATE_ID = '33333333333333333333333333333333';

    /**
     * Was: Gültige Template-ID, Template hat einen Type mit `technicalName`.
     * Warum: Der Kernvertrag — ohne diese Auflösung matcht die Mail-Whitelist
     *        niemals ihren Default `order_confirmation_mail`.
     * Erwartet: `order_confirmation_mail`.
     */
    public function testResolvesTechnicalName(): void
    {
        $resolver = $this->resolver($this->template('order_confirmation_mail'));

        self::assertSame(
            'order_confirmation_mail',
            $resolver->resolveTechnicalName(self::TEMPLATE_ID, Context::createDefaultContext()),
        );
    }

    /**
     * Was: `null` bzw. eine syntaktisch ungültige ID.
     * Warum: Guard-Clause vor dem DAL-Roundtrip; eine kaputte ID darf keine
     *        Exception aus der DAL provozieren.
     * Erwartet: `null`, ohne dass das Repository befragt wird.
     */
    public function testReturnsNullForMissingOrInvalidId(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('search');

        $resolver = new MailTemplateTypeResolver($repository, new NullLogger());
        $context = Context::createDefaultContext();

        self::assertNull($resolver->resolveTechnicalName(null, $context));
        self::assertNull($resolver->resolveTechnicalName('kein-uuid', $context));
    }

    /**
     * Was: Template existiert nicht mehr (gelöscht) oder hat keinen Type.
     * Warum: Fail-closed — der Aufrufer darf keinen Anhang an eine Mail hängen,
     *        deren Typ unbekannt ist.
     * Erwartet: `null`.
     */
    public function testReturnsNullWhenTemplateOrTypeMissing(): void
    {
        $context = Context::createDefaultContext();

        self::assertNull($this->resolver(null)->resolveTechnicalName(self::TEMPLATE_ID, $context));
        self::assertNull($this->resolver($this->template(null))->resolveTechnicalName(self::TEMPLATE_ID, $context));
    }

    /**
     * Was: Die DAL wirft (DB weg, Scope-Problem).
     * Warum: Ein Lookup-Fehler darf den Mail-Versand nicht abbrechen — die Mail
     *        soll rausgehen, nur eben ohne Anhang.
     * Erwartet: `null` statt Exception.
     */
    public function testReturnsNullAndSwallowsLookupFailure(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willThrowException(new RuntimeException('db down'));

        $resolver = new MailTemplateTypeResolver($repository, new NullLogger());

        self::assertNull($resolver->resolveTechnicalName(self::TEMPLATE_ID, Context::createDefaultContext()));
    }

    /**
     * Was: Zweimalige Auflösung derselben ID.
     * Warum: `MailBeforeValidateEvent` feuert pro Mail; ohne Cache entstünde je
     *        Mail ein zusätzlicher DAL-Roundtrip im Versand-Hot-Path.
     * Erwartet: Genau ein `search`-Aufruf, beide Male dasselbe Ergebnis.
     */
    public function testCachesLookupPerTemplateId(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('search')
            ->willReturnCallback($this->searchResult($this->template('order_confirmation_mail')));

        $resolver = new MailTemplateTypeResolver($repository, new NullLogger());
        $context = Context::createDefaultContext();

        self::assertSame('order_confirmation_mail', $resolver->resolveTechnicalName(self::TEMPLATE_ID, $context));
        self::assertSame('order_confirmation_mail', $resolver->resolveTechnicalName(self::TEMPLATE_ID, $context));
    }

    /**
     * Was: `reset()` nach einer Auflösung.
     * Warum: Im Worker-Mode (Swoole/RoadRunner/FrankenPHP) überlebt der Service
     *        den Request. Ein nicht geleerter Cache würde ein zwischenzeitlich
     *        geändertes Template-Type-Mapping über Requests hinweg festhalten.
     * Erwartet: Nach `reset()` wird erneut gegen die DAL gefragt.
     */
    public function testResetClearsCache(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(2))
            ->method('search')
            ->willReturnCallback($this->searchResult($this->template('order_confirmation_mail')));

        $resolver = new MailTemplateTypeResolver($repository, new NullLogger());
        $context = Context::createDefaultContext();

        $resolver->resolveTechnicalName(self::TEMPLATE_ID, $context);
        $resolver->reset();
        $resolver->resolveTechnicalName(self::TEMPLATE_ID, $context);
    }

    private function resolver(?MailTemplateEntity $template): MailTemplateTypeResolver
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback($this->searchResult($template));

        return new MailTemplateTypeResolver($repository, new NullLogger());
    }

    /**
     * @return callable(Criteria, Context): EntitySearchResult<MailTemplateCollection>
     */
    private function searchResult(?MailTemplateEntity $template): callable
    {
        $collection = new MailTemplateCollection($template === null ? [] : [$template]);

        return static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
            'mail_template',
            $collection->count(),
            $collection,
            null,
            $criteria,
            $context,
        );
    }

    private function template(?string $technicalName): MailTemplateEntity
    {
        $template = new MailTemplateEntity();
        $template->setId(self::TEMPLATE_ID);
        $template->setUniqueIdentifier(self::TEMPLATE_ID);

        if ($technicalName !== null) {
            $type = new MailTemplateTypeEntity();
            $type->setId('44444444444444444444444444444444');
            $type->setUniqueIdentifier('44444444444444444444444444444444');
            $type->setTechnicalName($technicalName);
            $template->setMailTemplateType($type);
        }

        return $template;
    }
}
