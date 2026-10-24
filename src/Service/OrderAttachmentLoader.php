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
 * Liest Anhänge über die DAL, immer mitsamt `media` und nur an der Live-Fassung der Bestellung.
 *
 * Alle Leser gehen über diese Klasse, damit niemand den Versionsfilter vergisst. Das mitgeladene
 * Medium erspart Mail-Subscriber und Download eine zweite Abfrage.
 */
final class OrderAttachmentLoader
{
    // Obergrenze je Abfrage, damit eine ungewöhnlich große Bestellung oder Kontoseite den Speicher
    // nicht sprengt. Bei höchstens 50 Dateien je Bestellung deckt sie 40 Bestellungen voll ab;
    // was darüber liegt, fällt ohne Meldung weg.
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
        // Nur die Live-Fassung: Entwürfe aus einer Bearbeitung in der Verwaltung dürfen sich nicht
        // mit den sichtbaren Daten mischen.
        $criteria->addFilter(new EqualsFilter('orderVersionId', Defaults::LIVE_VERSION));
        $criteria->addAssociation('media');
        $criteria->setLimit(1);

        try {
            $attachment = $this->attachmentRepository->search($criteria, $context)->getEntities()->first();
        } catch (InvalidUuidException) {
            throw new AttachmentNotFoundException($attachmentId);
        }

        if (!$attachment instanceof OrderAttachmentEntity) {
            throw new AttachmentNotFoundException($attachmentId);
        }

        return $attachment;
    }

    /**
     * Lädt den Anhang nur, wenn er zu einer Bestellung des angegebenen Kunden gehört.
     *
     * Die Bedingung `order.orderCustomer.customerId` steht in derselben Abfrage. „Nicht gefunden"
     * und „gehört jemand anderem" enden deshalb in derselben Ausnahme, und eine fremde ID verrät
     * nicht, ob es sie gibt.
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
            $attachment = $this->attachmentRepository->search($criteria, $context)->getEntities()->first();
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
     * Lädt die Anhänge aller Bestellungen einer Seite im Kundenkonto mit einer einzigen Abfrage
     * statt mit einer je Bestellung.
     *
     * @param list<string> $orderIds
     *
     * @return array<string, OrderAttachmentCollection> Schlüssel ist die Bestell-ID
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
