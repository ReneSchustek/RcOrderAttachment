<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\MessageHandler;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Message\RetryOrderAttachmentLinkMessage;
use Ruhrcoder\RcOrderAttachment\MessageHandler\RetryOrderAttachmentLinkHandler;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentManagerInterface;
use RuntimeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Unit-Tests für den idempotenten Attach-Retry-Handler.
 * Pattern: echter {@see OrderAttachmentLoader} mit gemocktem Repository.
 */
final class RetryOrderAttachmentLinkHandlerTest extends TestCase
{
    private const ORDER_ID = '99999999999999999999999999999999';

    /**
     * @param array{mediaId: string, originalFileName: string, mimeType: string, fileSize: int} ...$uploads
     */
    private function message(int $attempt, array ...$uploads): RetryOrderAttachmentLinkMessage
    {
        return new RetryOrderAttachmentLinkMessage(self::ORDER_ID, array_values($uploads), $attempt);
    }

    /**
     * @return array{mediaId: string, originalFileName: string, mimeType: string, fileSize: int}
     */
    private function upload(string $mediaId): array
    {
        return ['mediaId' => $mediaId, 'originalFileName' => $mediaId . '.pdf', 'mimeType' => 'application/pdf', 'fileSize' => 100];
    }

    /**
     * Was: Ein Media der Nachricht ist bereits verknüpft, das andere nicht.
     * Warum: Idempotenz — ein Retry nach Teil-Erfolg darf keine Doppel-Zeile für
     *        das bereits verknüpfte Media anlegen.
     * Erwartet: Genau EIN `attach` (nur für das noch nicht verknüpfte Media),
     *           kein Re-Dispatch.
     */
    public function testSkipsAlreadyLinkedAndAttachesOnlyMissing(): void
    {
        $existing = new OrderAttachmentEntity();
        $existing->setId('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $existing->setUniqueIdentifier('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $existing->setMediaId('media-linked');

        $manager = $this->createMock(OrderAttachmentManagerInterface::class);
        $manager->expects(self::once())->method('attach')
            ->with(self::ORDER_ID, 'media-missing')
            ->willReturn(new OrderAttachmentEntity());

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new RetryOrderAttachmentLinkHandler($manager, $this->loader([$existing]), $bus, new NullLogger());
        $handler($this->message(1, $this->upload('media-linked'), $this->upload('media-missing')));
    }

    /**
     * Was: Attach scheitert weiterhin bei attempt = 1.
     * Warum: Der Handler zählt selbst und plant mit Backoff neu ein.
     * Erwartet: Re-Dispatch mit attempt = 2 und 60 s DelayStamp.
     */
    public function testRedispatchesWithBackoffOnPersistentFailure(): void
    {
        $manager = $this->createMock(OrderAttachmentManagerInterface::class);
        $manager->method('attach')->willThrowException(new RuntimeException('db down'));

        $dispatched = null;
        $stamps = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (object $message, array $envelopeStamps = []) use (&$dispatched, &$stamps): Envelope {
                $dispatched = $message;
                $stamps = $envelopeStamps;

                return new Envelope($message);
            },
        );

        $handler = new RetryOrderAttachmentLinkHandler($manager, $this->loader([]), $bus, new NullLogger());
        $handler($this->message(1, $this->upload('media-1')));

        self::assertInstanceOf(RetryOrderAttachmentLinkMessage::class, $dispatched);
        self::assertSame(2, $dispatched->attempt);
        self::assertInstanceOf(DelayStamp::class, $stamps[0]);
        self::assertSame(60_000, $stamps[0]->getDelay());
    }

    /**
     * Was: Attach scheitert bei attempt = MAX_ATTEMPTS.
     * Warum: Endlos-Schleife verhindern — final loggen, damit der Betrieb
     *        manuell nacharbeiten kann.
     * Erwartet: Kein weiterer Dispatch.
     */
    public function testStopsAtMaxAttempts(): void
    {
        $manager = $this->createMock(OrderAttachmentManagerInterface::class);
        $manager->method('attach')->willThrowException(new RuntimeException('db down'));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = new RetryOrderAttachmentLinkHandler($manager, $this->loader([]), $bus, new NullLogger());
        $handler($this->message(RetryOrderAttachmentLinkHandler::MAX_ATTEMPTS, $this->upload('media-1')));
    }

    /**
     * @param list<OrderAttachmentEntity> $entities
     */
    private function loader(array $entities): OrderAttachmentLoader
    {
        $collection = new OrderAttachmentCollection($entities);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'rc_order_attachment',
                $collection->count(),
                $collection,
                null,
                $criteria,
                $context,
            ),
        );

        return new OrderAttachmentLoader($repository);
    }
}
