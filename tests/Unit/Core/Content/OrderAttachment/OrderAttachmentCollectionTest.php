<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Core\Content\OrderAttachment;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;

/**
 * Unit-Tests für die typed DAL-Collection — sichert die ID-Helfer, Filter und
 * Summen-Aggregation, die service-übergreifend genutzt werden.
 */
final class OrderAttachmentCollectionTest extends TestCase
{
    /**
     * Was: `getOrderIds()` und `getMediaIds()` über eine Mix-Collection.
     * Warum: Diese Helfer treiben Batch-Loading, Indexierung und Delete-Pfade —
     *        Reihenfolge und Vollständigkeit der IDs sind kritisch.
     * Erwartet: Beide Listen enthalten alle IDs in Insertion-Reihenfolge.
     */
    public function testGetOrderAndMediaIds(): void
    {
        $collection = new OrderAttachmentCollection([
            $this->entity('id-1', 'order-1', 'media-1'),
            $this->entity('id-2', 'order-1', 'media-2'),
            $this->entity('id-3', 'order-2', 'media-3'),
        ]);

        self::assertSame(['order-1', 'order-1', 'order-2'], $collection->getOrderIds());
        self::assertSame(['media-1', 'media-2', 'media-3'], $collection->getMediaIds());
    }

    /**
     * Was: `filterByOrderId` reduziert die Collection auf Treffer einer Order.
     * Warum: Nach einem Batch-Load (mehrere Orders) müssen Caller pro Order
     *        ihre eigene Sub-Collection ziehen — Standard-Pattern in der
     *        Account-Order-Pagelet-Logik.
     * Erwartet: Eine `OrderAttachmentCollection` mit nur dem Match.
     */
    public function testFilterByOrderId(): void
    {
        $collection = new OrderAttachmentCollection([
            $this->entity('id-1', 'order-1', 'media-1'),
            $this->entity('id-2', 'order-2', 'media-2'),
        ]);

        $filtered = $collection->filterByOrderId('order-1');

        self::assertCount(1, $filtered);
        self::assertSame('id-1', $filtered->first()?->getId());
    }

    /**
     * Was: `getTotalFileSize()` summiert die `fileSize` aller Entities.
     * Warum: Total-Size-Validator und Storage-Telemetrie nutzen das Summen-
     *        Aggregat. Eine falsche Addition (z. B. Overflow) würde Limits
     *        ineffektiv machen.
     * Erwartet: 1024 + 2048 = 3072.
     */
    public function testTotalFileSize(): void
    {
        $collection = new OrderAttachmentCollection([
            $this->entity('id-1', 'order-1', 'media-1', 1024),
            $this->entity('id-2', 'order-1', 'media-2', 2048),
        ]);

        self::assertSame(3072, $collection->getTotalFileSize());
    }

    private function entity(string $id, string $orderId, string $mediaId, int $size = 0): OrderAttachmentEntity
    {
        $entity = new OrderAttachmentEntity();
        $entity->setUniqueIdentifier($id);
        $entity->setId($id);
        $entity->setOrderId($orderId);
        $entity->setOrderVersionId('00000000000000000000000000000000');
        $entity->setMediaId($mediaId);
        $entity->setOriginalFileName('file.pdf');
        $entity->setMimeType('application/pdf');
        $entity->setFileSize($size);

        return $entity;
    }
}
