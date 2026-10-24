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
 * Prüft die Auflösung einer `templateId` zum `technicalName` ihres Vorlagentyps. Shopware
 * liefert im `MailBeforeValidateEvent` nur die ID, die Freigabeliste des Plugins arbeitet mit
 * dem technischen Namen, und der Resolver ist die einzige Stelle dazwischen.
 *
 * Der Resolver ist echt, gemockt ist nur das `EntityRepository`.
 */
final class MailTemplateTypeResolverTest extends TestCase
{
    private const TEMPLATE_ID = '33333333333333333333333333333333';

    /**
     * Ohne diese Auflösung trifft die Freigabeliste nie ihre Vorgabe `order_confirmation_mail`.
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
     * Eine fehlende oder kaputte ID wird vor dem Repository abgefangen, sonst würfe die DAL.
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
     * Ist die Vorlage gelöscht oder ohne Typ, liefert der Resolver `null`. Eine Mail unbekannten
     * Typs bekommt damit keinen Anhang.
     */
    public function testReturnsNullWhenTemplateOrTypeMissing(): void
    {
        $context = Context::createDefaultContext();

        self::assertNull($this->resolver(null)->resolveTechnicalName(self::TEMPLATE_ID, $context));
        self::assertNull($this->resolver($this->template(null))->resolveTechnicalName(self::TEMPLATE_ID, $context));
    }

    /**
     * Scheitert die Abfrage, geht die Mail trotzdem hinaus, nur ohne Anhang.
     */
    public function testReturnsNullAndSwallowsLookupFailure(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willThrowException(new RuntimeException('db down'));

        $resolver = new MailTemplateTypeResolver($repository, new NullLogger());

        self::assertNull($resolver->resolveTechnicalName(self::TEMPLATE_ID, Context::createDefaultContext()));
    }

    /**
     * `MailBeforeValidateEvent` kommt bei jeder Mail. Ohne Zwischenspeicher kostete jede eine
     * weitere Datenbankabfrage im Versandweg.
     */
    public function testCachesLookupPerTemplateId(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('search')
            ->willReturnCallback($this->searchResult($this->template('order_confirmation_mail')));

        $resolver = new MailTemplateTypeResolver($repository, new NullLogger());
        $context = Context::createDefaultContext();

        // Die Ergebnisse stehen in Variablen, weil die statische Analyse zwei gleiche Aufrufe in
        // einer Zusicherung für denselben Wert hält und den Vergleich als immer wahr meldet.
        $first = $resolver->resolveTechnicalName(self::TEMPLATE_ID, $context);
        $second = $resolver->resolveTechnicalName(self::TEMPLATE_ID, $context);

        self::assertSame('order_confirmation_mail', $first);
        self::assertSame($first, $second);
    }

    /**
     * In einem langlebigen Worker (Swoole, RoadRunner, FrankenPHP) überlebt der Dienst die
     * Anfrage. Ohne `reset()` hielte der Zwischenspeicher eine geänderte Zuordnung über Anfragen
     * hinweg fest.
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
