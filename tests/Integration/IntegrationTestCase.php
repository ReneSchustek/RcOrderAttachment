<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Gemeinsame Fixtures fuer Integration-Tests.
 *
 * Eine Bestellung anzulegen braucht in Shopware ein gutes Dutzend Pflichtfelder samt
 * Rundungs-Konfiguration und einer Rechnungsadresse, die im selben Schreibvorgang entstehen
 * muss. Das gehoert einmal an eine Stelle und nicht in jede Testklasse kopiert.
 */
abstract class IntegrationTestCase extends TestCase
{
    use ContainerAccessTrait;
    use IntegrationTestBehaviour;

    protected Context $context;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
    }

    protected function createTestOrder(): string
    {
        // Erzeugt eine Test-Order via Shopware-Test-Fixture-Helpers.
        // Implementation hängt von der konkreten Shopware-Test-Bootstrap-Variante ab.
        // Alternativ: `OrderFixture` aus `shopware/core/Test/Fixtures` oder eigene Mini-Order.
        $orderId = Uuid::randomHex();
        // Die Rechnungsadresse muss im selben Schreibvorgang entstehen und von
        // `billingAddressId` referenziert werden. Eine lose UUID ohne passenden Datensatz
        // laesst den Fremdschluessel greifen — der Test brach dann mit einem Fehler ab,
        // statt das zu pruefen, wofuer er da ist.
        $billingAddressId = Uuid::randomHex();
        static::repository('order.repository')->create([[
            'id' => $orderId,
            'orderNumber' => 'TEST-' . $orderId,
            'orderDateTime' => '2026-05-20 10:00:00',
            'currencyId' => Defaults::CURRENCY,
            'currencyFactor' => 1.0,
            'salesChannelId' => $this->getValidSalesChannelId(),
            'stateId' => $this->getOrderStateId('open'),
            'price' => [
                'netPrice' => 100,
                'totalPrice' => 119,
                'positionPrice' => 100,
                'rawTotal' => 119,
                'taxStatus' => 'gross',
                'calculatedTaxes' => [],
                'taxRules' => [],
            ],
            'shippingCosts' => [
                'unitPrice' => 0,
                'totalPrice' => 0,
                'quantity' => 1,
                'calculatedTaxes' => [],
                'taxRules' => [],
            ],
            // Shopware 6.7+ verlangt CashRoundingConfig fuer Order-Entity (Pflichtfelder).
            'totalRounding' => [
                'decimals' => 2,
                'interval' => 0.01,
                'roundForNet' => true,
            ],
            'itemRounding' => [
                'decimals' => 2,
                'interval' => 0.01,
                'roundForNet' => true,
            ],
            'orderCustomer' => [
                'email' => 'integration-test@example.com',
                'firstName' => 'Test',
                'lastName' => 'Customer',
                'salutationId' => $this->getSalutationId(),
            ],
            'billingAddressId' => $billingAddressId,
            'addresses' => [[
                'id' => $billingAddressId,
                'salutationId' => $this->getSalutationId(),
                'firstName' => 'Test',
                'lastName' => 'Customer',
                'street' => 'Teststrasse 1',
                'zipcode' => '12345',
                'city' => 'Testort',
                'countryId' => $this->getCountryId(),
            ]],
        ]], $this->context);

        return $orderId;
    }

    protected function createTestMedia(): string
    {
        $mediaId = Uuid::randomHex();
        static::repository('media.repository')->create([[
            'id' => $mediaId,
            'private' => true,
        ]], $this->context);

        return $mediaId;
    }

    protected function getCountryId(): string
    {
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->setLimit(1);

        $id = static::repository('country.repository')->searchIds($criteria, $this->context)->firstId();
        self::assertNotNull($id, 'Mindestens ein Land muss existieren');

        return $id;
    }

    protected function getValidSalesChannelId(): string
    {
        $repo = static::repository('sales_channel.repository');
        $id = $repo->searchIds(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria(), $this->context)->firstId();
        self::assertNotNull($id, 'Mindestens ein Sales-Channel muss existieren');

        return $id;
    }

    protected function getOrderStateId(string $technicalName): string
    {
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter('technicalName', $technicalName));
        $criteria->setLimit(1);

        return (string) static::repository('state_machine_state.repository')
            ->searchIds($criteria, $this->context)->firstId();
    }

    protected function getSalutationId(): string
    {
        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->setLimit(1);

        return (string) static::repository('salutation.repository')
            ->searchIds($criteria, $this->context)->firstId();
    }
}
