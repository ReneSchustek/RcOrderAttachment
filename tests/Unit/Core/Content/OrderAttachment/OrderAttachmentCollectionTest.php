<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Core\Content\OrderAttachment;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;

/**
 * Prüft die Helfer der typisierten Anhangssammlung: ID-Listen, Filter nach Bestellung und
 * Summe der Dateigrößen. Im Plugin ruft sie derzeit kein Dienst auf; der Test hält ihren
 * Vertrag fest, solange sie öffentlich angeboten werden.
 */
final class OrderAttachmentCollectionTest extends TestCase
{
    /**
     * Die Listen behalten Doppelte und die Reihenfolge des Einfügens; `order-1` steht deshalb
     * zweimal darin und wird nicht zusammengefasst.
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
     * Nach dem Laden für mehrere Bestellungen zieht der Filter die Anhänge einer einzelnen heraus.
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
     * Die Summe läuft über `fileSize` aller Einträge.
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
