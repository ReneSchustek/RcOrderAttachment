<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * Typed DAL-Collection für {@see OrderAttachmentEntity}. Bündelt
 * Service-übergreifend genutzte Hilfsabfragen (IDs, Filter, Summen), damit
 * Konsumenten nicht jedes Mal über `getElements()` schleifen müssen.
 *
 * @extends EntityCollection<OrderAttachmentEntity>
 */
final class OrderAttachmentCollection extends EntityCollection
{
    /**
     * @return list<string>
     */
    public function getOrderIds(): array
    {
        return array_values($this->fmap(
            static fn (OrderAttachmentEntity $attachment): string => $attachment->getOrderId()
        ));
    }

    /**
     * @return list<string>
     */
    public function getMediaIds(): array
    {
        return array_values($this->fmap(
            static fn (OrderAttachmentEntity $attachment): string => $attachment->getMediaId()
        ));
    }

    public function filterByOrderId(string $orderId): self
    {
        return $this->filter(
            static fn (OrderAttachmentEntity $attachment): bool => $attachment->getOrderId() === $orderId
        );
    }

    public function getTotalFileSize(): int
    {
        $total = 0;
        foreach ($this->getElements() as $attachment) {
            $total += $attachment->getFileSize();
        }

        return $total;
    }

    protected function getExpectedClass(): string
    {
        return OrderAttachmentEntity::class;
    }
}
