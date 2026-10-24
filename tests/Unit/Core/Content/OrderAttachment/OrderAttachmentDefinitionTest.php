<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Core\Content\OrderAttachment;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentDefinition;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;

/**
 * Prüft Name, Klassen und Felder der DAL-Definition. Eine Abweichung fällt sonst erst zur
 * Laufzeit auf, beim ersten Lesen oder Schreiben eines Anhangs.
 */
final class OrderAttachmentDefinitionTest extends TestCase
{
    /**
     * Der Name steht zugleich als Text im Tag `shopware.entity.definition` der services.xml und
     * in jedem Dienstnamen `rc_order_attachment.repository`. Eine Umbenennung allein in der
     * Konstante bräche diese Stellen und alle Plugins, die das Repository nutzen.
     */
    public function testEntityNameConstant(): void
    {
        self::assertSame('rc_order_attachment', OrderAttachmentDefinition::ENTITY_NAME);
    }

    /**
     * Die DAL bildet aus diesem Namen den Dienst `rc_order_attachment.repository`.
     */
    public function testGetEntityName(): void
    {
        self::assertSame('rc_order_attachment', (new OrderAttachmentDefinition())->getEntityName());
    }

    /**
     * Die DAL erzeugt beim Lesen Objekte dieser Klasse; eine falsche Angabe führt beim ersten
     * Lesen zu Typfehlern.
     */
    public function testGetEntityClass(): void
    {
        self::assertSame(OrderAttachmentEntity::class, (new OrderAttachmentDefinition())->getEntityClass());
    }

    /**
     * `search()` liefert seine Ergebnisse in dieser Sammlung. Mit einer anderen Klasse fehlten
     * deren Helfer, und die Typangaben der Repositorys stimmten nicht mehr.
     */
    public function testGetCollectionClass(): void
    {
        self::assertSame(OrderAttachmentCollection::class, (new OrderAttachmentDefinition())->getCollectionClass());
    }

    /**
     * Ein versehentlich entferntes Feld muss auffallen, besonders `orderVersionId`, an dem die
     * Trennung von Live-Bestellung und Entwurf hängt.
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
