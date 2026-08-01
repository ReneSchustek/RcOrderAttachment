<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service;

use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Service\Exception\AttachmentNotFoundException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Exception\InvalidUuidException;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Liest Anhänge konsistent über die DAL — inklusive `media`, damit nachgelagerte
 * Komponenten (Mail-Subscriber, Cleanup-Tasks) ohne Zusatz-Roundtrip arbeiten.
 */
final class OrderAttachmentLoader
{
    private const MAX_BATCH_SIZE = 2000;

    /**
     * @param EntityRepository<OrderAttachmentCollection> $attachmentRepository
     */
    public function __construct(
        private readonly EntityRepository $attachmentRepository,
    ) {
    }

    public function loadById(string $attachmentId, Context $context): OrderAttachmentEntity
    {
        if (!Uuid::isValid($attachmentId)) {
            throw new AttachmentNotFoundException($attachmentId);
        }

        $criteria = new Criteria([$attachmentId]);
        // Explizit auf LIVE-Order-Version filtern: Order-Drafts (z. B. aus Admin-Edit)
        // dürfen nie mit produktiv sichtbaren Daten vermischt werden.
        $criteria->addFilter(new EqualsFilter('orderVersionId', Defaults::LIVE_VERSION));
        $criteria->addAssociation('media');
        $criteria->setLimit(1);

        try {
            $attachment = $this->attachmentRepository->search($criteria, $context)->first();
        } catch (InvalidUuidException) {
            throw new AttachmentNotFoundException($attachmentId);
        }

        if (!$attachment instanceof OrderAttachmentEntity) {
            throw new AttachmentNotFoundException($attachmentId);
        }

        return $attachment;
    }

    /**
     * Lädt den Anhang nur, wenn er zur Order des angegebenen Customers gehört.
     *
     * Owner-Check über `order.orderCustomer.customerId = :customerId` — Customer
     * sieht nur seine eigenen Anhänge. „Nicht gefunden" und „nicht autorisiert"
     * liefern denselben Fehler, damit über die Antwortzeit kein IDOR-Disclosure
     * möglich ist.
     */
    public function loadByIdForCustomer(string $attachmentId, string $customerId, Context $context): OrderAttachmentEntity
    {
        if (!Uuid::isValid($attachmentId) || !Uuid::isValid($customerId)) {
            throw new AttachmentNotFoundException($attachmentId);
        }

        $criteria = new Criteria([$attachmentId]);
        $criteria->addFilter(new EqualsFilter('orderVersionId', Defaults::LIVE_VERSION));
        $criteria->addFilter(new EqualsFilter('order.orderCustomer.customerId', $customerId));
        $criteria->addAssociation('media');
        $criteria->setLimit(1);

        try {
            $attachment = $this->attachmentRepository->search($criteria, $context)->first();
        } catch (InvalidUuidException) {
            throw new AttachmentNotFoundException($attachmentId);
        }

        if (!$attachment instanceof OrderAttachmentEntity) {
            throw new AttachmentNotFoundException($attachmentId);
        }

        return $attachment;
    }

    public function loadForOrder(string $orderId, Context $context): OrderAttachmentCollection
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsFilter('orderVersionId', Defaults::LIVE_VERSION));
        $criteria->addAssociation('media');
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit(self::MAX_BATCH_SIZE);

        return $this->attachmentRepository->search($criteria, $context)->getEntities();
    }

    /**
     * Batch-Load für die Account-Order-Page. Vermeidet N+1, indem alle Order-IDs
     * in einer einzigen DAL-Abfrage abgehandelt werden.
     *
     * @param list<string> $orderIds
     *
     * @return array<string, OrderAttachmentCollection> Key = Order-ID
     */
    public function loadForOrderIds(array $orderIds, Context $context): array
    {
        $orderIds = array_values(array_unique(array_filter($orderIds, Uuid::isValid(...))));
        if ($orderIds === []) {
            return [];
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('orderId', $orderIds));
        $criteria->addFilter(new EqualsFilter('orderVersionId', Defaults::LIVE_VERSION));
        $criteria->addAssociation('media');
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit(self::MAX_BATCH_SIZE);

        $result = $this->attachmentRepository->search($criteria, $context)->getEntities();

        $grouped = [];
        foreach ($result as $attachment) {
            $orderId = $attachment->getOrderId();
            $grouped[$orderId] ??= new OrderAttachmentCollection();
            $grouped[$orderId]->add($attachment);
        }

        return $grouped;
    }
}
