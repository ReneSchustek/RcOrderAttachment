<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\MessageHandler;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Message\RetryFailedMailAttachmentsMessage;
use Ruhrcoder\RcOrderAttachment\MessageHandler\RetryFailedMailAttachmentsHandler;
use Ruhrcoder\RcOrderAttachment\Service\Mail\MailAttachmentStatusTrackerInterface;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use RuntimeException;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Prüft den zuschaltbaren Handler, der Mailanhänge nach einem Lesefehler erneut versucht und
 * am Ende den Status für den Betrieb festhält.
 *
 * Der Handler zählt die Versuche selbst und schickt bei Misserfolg eine neue Nachricht mit
 * `attempt + 1` und {@see DelayStamp}. Eine `RecoverableMessageHandlingException` stellte
 * dieselbe Nachricht unverändert erneut zu, `attempt` bliebe für immer 1, und die
 * Abbruchbedingung wäre unerreichbar. Gemockt sind nur die Grenzen zu Shopware.
 */
final class RetryFailedMailAttachmentsHandlerTest extends TestCase
{
    private const ORDER_ID = '99999999999999999999999999999999';

    /**
     * Hat die Aufbewahrungsbereinigung alle Anhänge schon gelöscht, kann kein weiterer Versuch
     * gelingen. Der Status geht sofort auf `failed`, statt die Versuche abzuarbeiten.
     */
    public function testNoAttachmentsMarksFailed(): void
    {
        $tracker = $this->createMock(MailAttachmentStatusTrackerInterface::class);
        $tracker->expects(self::once())->method('markFailed')->with(self::ORDER_ID);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = $this->handler([], $tracker, $bus);

        $handler(new RetryFailedMailAttachmentsMessage(self::ORDER_ID));
    }

    /**
     * Der Fall, für den es die Wiederholung gibt: Der Speicher antwortet wieder, und der Status
     * wechselt auf `attached`.
     */
    public function testReadableAttachmentsMarkAttached(): void
    {
        $tracker = $this->createMock(MailAttachmentStatusTrackerInterface::class);
        $tracker->expects(self::once())->method('markAttached')->with(self::ORDER_ID);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = $this->handler(
            [$this->attachment('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')],
            $tracker,
            $bus,
            mediaContent: 'content',
        );

        $handler(new RetryFailedMailAttachmentsMessage(self::ORDER_ID));
    }

    /**
     * Würfe der Handler hier eine `RecoverableMessageHandlingException`, stellte Messenger
     * dieselbe Nachricht mit `attempt = 1` erneut zu. Die Zählung stünde still, und `failed`
     * würde nie geschrieben. Erwartet wird deshalb eine neue Nachricht mit `attempt = 2` und
     * einer Minute Wartezeit, der Grundwert des Handlers.
     */
    public function testUnreadableAttachmentReschedulesWithIncrementedAttempt(): void
    {
        $tracker = $this->createMock(MailAttachmentStatusTrackerInterface::class);
        $tracker->expects(self::never())->method('markAttached');
        $tracker->expects(self::never())->method('markFailed');

        $dispatched = null;
        $stamps = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $message, array $envelopeStamps = []) use (&$dispatched, &$stamps): Envelope {
                $dispatched = $message;
                $stamps = $envelopeStamps;

                return new Envelope($message);
            });

        $handler = $this->handler(
            [$this->attachment('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')],
            $tracker,
            $bus,
            storageBroken: true,
        );

        $handler(new RetryFailedMailAttachmentsMessage(self::ORDER_ID, attempt: 1));

        self::assertInstanceOf(RetryFailedMailAttachmentsMessage::class, $dispatched);
        self::assertSame(2, $dispatched->attempt, 'attempt muss hochgezählt werden');
        self::assertSame(self::ORDER_ID, $dispatched->orderId);

        self::assertCount(1, $stamps);
        self::assertInstanceOf(DelayStamp::class, $stamps[0]);
        self::assertSame(60_000, $stamps[0]->getDelay(), 'Versuch 1 wartet 60 s');
    }

    /**
     * Die Wartezeit verdoppelt sich je Versuch, hier auf zwei Minuten. Sonst fragte der Worker
     * im Minutentakt einen ausgefallenen Speicher ab.
     */
    public function testBackoffGrowsExponentially(): void
    {
        $stamps = [];
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(
            static function (object $message, array $envelopeStamps = []) use (&$stamps): Envelope {
                $stamps = $envelopeStamps;

                return new Envelope($message);
            },
        );

        $handler = $this->handler(
            [$this->attachment('cccccccccccccccccccccccccccccccc')],
            $this->createMock(MailAttachmentStatusTrackerInterface::class),
            $bus,
            storageBroken: true,
        );

        $handler(new RetryFailedMailAttachmentsMessage(self::ORDER_ID, attempt: 2));

        self::assertInstanceOf(DelayStamp::class, $stamps[0]);
        self::assertSame(120_000, $stamps[0]->getDelay());
    }

    /**
     * Nach dem letzten Versuch endet die Kette mit `failed`, damit der Betrieb sieht, dass er
     * die Anhänge selbst zustellen muss.
     */
    public function testExhaustedAttemptsMarkFailedAndStopRescheduling(): void
    {
        $tracker = $this->createMock(MailAttachmentStatusTrackerInterface::class);
        $tracker->expects(self::once())->method('markFailed')->with(self::ORDER_ID);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $handler = $this->handler(
            [$this->attachment('dddddddddddddddddddddddddddddddd')],
            $tracker,
            $bus,
            storageBroken: true,
        );

        $handler(new RetryFailedMailAttachmentsMessage(self::ORDER_ID, attempt: RetryFailedMailAttachmentsHandler::MAX_ATTEMPTS));
    }

    /**
     * Bleibt nach dem letzten Versuch nur einer von zwei Anhängen unlesbar, ist `failed` falsch,
     * denn ein Teil kam an. Der Status lautet dann `partial_failure`.
     */
    public function testExhaustedPartialFailureMarksPartial(): void
    {
        $readable = $this->attachment('11111111111111111111111111111111');
        $broken = $this->attachment('22222222222222222222222222222222');
        // Ein fehlendes Medium zählt in `countUnreadable()` als Fehler.
        $broken->setMedia(null);

        $tracker = $this->createMock(MailAttachmentStatusTrackerInterface::class);
        $tracker->expects(self::once())->method('markPartialFailure')->with(self::ORDER_ID);
        $tracker->expects(self::never())->method('markFailed');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        // Der Speicher funktioniert, nur `$broken` ohne Medium fällt durch.
        $handler = $this->handler([$readable, $broken], $tracker, $bus, mediaContent: 'ok');

        $handler(new RetryFailedMailAttachmentsMessage(self::ORDER_ID, attempt: RetryFailedMailAttachmentsHandler::MAX_ATTEMPTS));
    }

    /**
     * @param list<OrderAttachmentEntity> $attachments
     */
    private function handler(
        array $attachments,
        MailAttachmentStatusTrackerInterface $tracker,
        MessageBusInterface $bus,
        string $mediaContent = '',
        bool $storageBroken = false,
    ): RetryFailedMailAttachmentsHandler {
        $mediaService = $this->createMock(MediaService::class);
        if ($storageBroken) {
            $mediaService->method('loadFile')->willThrowException(new RuntimeException('storage down'));
        } else {
            $mediaService->method('loadFile')->willReturn($mediaContent);
        }

        return new RetryFailedMailAttachmentsHandler(
            $this->loader($attachments),
            $mediaService,
            $tracker,
            $bus,
            new NullLogger(),
        );
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

    private function attachment(string $id): OrderAttachmentEntity
    {
        $media = new MediaEntity();
        $media->setId('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
        $media->setUniqueIdentifier('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');

        $entity = new OrderAttachmentEntity();
        $entity->setId($id);
        $entity->setUniqueIdentifier($id);
        $entity->setOrderId(self::ORDER_ID);
        $entity->setOrderVersionId('00000000000000000000000000000000');
        $entity->setMediaId('eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee');
        $entity->setOriginalFileName($id . '.pdf');
        $entity->setMimeType('application/pdf');
        $entity->setFileSize(100);
        $entity->setMedia($media);

        return $entity;
    }
}
