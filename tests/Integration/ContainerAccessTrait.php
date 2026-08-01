<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Integration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Typisierte Zugriffe auf den Test-Container.
 *
 * `ContainerInterface::get()` gibt `object` zurück. Jeder Aufruf darauf ist damit
 * ungeprüft — ein falscher Dienst-Schlüssel fällt erst zur Laufzeit auf, und die
 * statische Analyse kann nichts davon sehen. Diese drei Helfer prüfen einmal und
 * geben den erwarteten Typ zurück.
 *
 * Liegt bewusst in einem Trait statt in der Basisklasse: nicht alle Integration-Tests
 * erben von `IntegrationTestCase`, einige binden nur das Shopware-Verhalten ein.
 */
trait ContainerAccessTrait
{
    /**
     * @return EntityRepository<covariant EntityCollection<Entity>>
     */
    protected static function repository(string $serviceId): EntityRepository
    {
        $repository = static::getContainer()->get($serviceId);
        static::assertInstanceOf(EntityRepository::class, $repository);

        return $repository;
    }

    /**
     * Ein beliebiger Dienst, anhand seiner Klasse geholt und geprüft.
     *
     * @template TService of object
     *
     * @param class-string<TService> $className
     *
     * @return TService
     */
    protected static function service(string $className): object
    {
        $service = static::getContainer()->get($className);
        static::assertInstanceOf($className, $service);

        return $service;
    }

    protected static function connection(): Connection
    {
        return static::service(Connection::class);
    }

    protected static function systemConfig(): SystemConfigService
    {
        return static::service(SystemConfigService::class);
    }
}
