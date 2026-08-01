<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Core\Content\OrderAttachment;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentDefinition;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;

/**
 * Unit-Tests für die DAL-Definition: prüft Entity-Name, Collection-/Entity-Klasse
 * und die FieldCollection. Schützt vor Schema-Drift beim Refactoring.
 */
final class OrderAttachmentDefinitionTest extends TestCase
{
    /**
     * Was: Klassen-Konstante `ENTITY_NAME` entspricht dem Tabellen-Namen.
     * Warum: Service-Tags (`shopware.entity.definition`) und externe Plugins
     *        referenzieren die Konstante. Eine Umbenennung würde
     *        Plugin-Interaktionen brechen.
     * Erwartet: `rc_order_attachment`.
     */
    public function testEntityNameConstant(): void
    {
        self::assertSame('rc_order_attachment', OrderAttachmentDefinition::ENTITY_NAME);
    }

    /**
     * Was: `getEntityName()` der Definition.
     * Warum: Shopware-DAL nutzt diesen String als Repository-Service-Suffix
     *        (`rc_order_attachment.repository`).
     * Erwartet: Selber Wert wie die Konstante.
     */
    public function testGetEntityName(): void
    {
        self::assertSame('rc_order_attachment', (new OrderAttachmentDefinition())->getEntityName());
    }

    /**
     * Was: `getEntityClass()` zeigt auf unsere Entity-Klasse.
     * Warum: DAL instanziiert beim Hydrate genau diese Klasse — falsche
     *        Verdrahtung würde Type-Errors beim ersten Read auslösen.
     * Erwartet: `OrderAttachmentEntity::class`.
     */
    public function testGetEntityClass(): void
    {
        self::assertSame(OrderAttachmentEntity::class, (new OrderAttachmentDefinition())->getEntityClass());
    }

    /**
     * Was: `getCollectionClass()` zeigt auf unsere typed Collection.
     * Warum: DAL hydratisiert `search()`-Ergebnisse in diese Collection. Eine
     *        falsche Klasse würde uns die `OrderAttachmentCollection`-Helfer
     *        kosten (Generics-Bruch).
     * Erwartet: `OrderAttachmentCollection::class`.
     */
    public function testGetCollectionClass(): void
    {
        self::assertSame(OrderAttachmentCollection::class, (new OrderAttachmentDefinition())->getCollectionClass());
    }

    /**
     * Was: `defineFields()` enthält alle Schema-Felder der Tabelle.
     * Warum: Der Klassen-Doc verspricht die FieldCollection abzusichern — ein
     *        versehentlich entferntes Feld (z. B. `orderVersionId`, das die
     *        Live-/Draft-Trennung trägt) muss der Test rot machen.
     * Erwartet: Alle erwarteten Property-Namen sind vorhanden.
     */
    public function testDefineFieldsContainsExpectedProperties(): void
    {
        $method = new ReflectionMethod(OrderAttachmentDefinition::class, 'defineFields');
        $method->setAccessible(true);

        $names = [];
        foreach ($method->invoke(new OrderAttachmentDefinition()) as $field) {
            $names[] = $field->getPropertyName();
        }

        foreach (['id', 'orderId', 'orderVersionId', 'mediaId', 'originalFileName', 'mimeType', 'fileSize', 'order', 'media', 'createdAt', 'updatedAt'] as $expected) {
            self::assertContains($expected, $names, \sprintf('Feld "%s" fehlt in defineFields()', $expected));
        }
    }
}
