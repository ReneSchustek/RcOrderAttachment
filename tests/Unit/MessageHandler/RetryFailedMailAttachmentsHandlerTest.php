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
 * Unit-Tests für den Opt-in-Retry-Handler.
 *
 * Der Handler zählt die Versuche selbst und dispatcht bei Misserfolg eine neue
 * Nachricht mit `attempt + 1` und {@see DelayStamp}. Eine
 * `RecoverableMessageHandlingException` würde dieselbe Nachricht unverändert
 * erneut zustellen — `attempt` bliebe für immer 1 und die Abbruchbedingung
 * wäre unerreichbar.
 *
 * Pattern: echte Instanzen mit gemockten Shopware-Boundaries.
 */
final class RetryFailedMailAttachmentsHandlerTest extends TestCase
{
    private const ORDER_ID = '99999999999999999999999999999999';

    /**
     * Was: Retry-Lauf für eine Order, deren Anhänge inzwischen alle gelöscht
     *      sind (z. B. durch Retention-Cleanup).
     * Warum: Endlos-Retry-Schleife muss vermieden werden — fehlende Anhänge
     *        können nicht „heilen", der Status muss final auf `failed`.
     * Erwartet: Genau ein `markFailed`-Aufruf, kein Re-Dispatch.
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
     * Was: Retry-Lauf, bei dem das Storage-Backend wieder antwortet und alle
     *      Anhänge gelesen werden können.
     * Warum: Genau der Happy-Path, für den der Retry existiert — ein
     *        temporärer Storage-Hiccup heilt sich und der Status soll auf
     *        `attached` aktualisiert werden.
     * Erwartet: `markAttached`-Aufruf, kein Re-Dispatch.
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
     * Was: Storage weiterhin defekt bei `attempt = 1`.
     * Warum: REGRESSION. Vorher warf der Handler eine
     *        `RecoverableMessageHandlingException`; Messenger stellte dieselbe
     *        Nachricht mit `attempt = 1` erneut zu. Die Zählung stand still,
     *        `MAX_ATTEMPTS` wurde nie erreicht und `failed` nie geschrieben.
     * Erwartet: Kein Throw, kein Status-Schreiben, stattdessen eine neue
     *           Nachricht mit `attempt = 2` und 60 s Verzögerung.
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
     * Was: Storage defekt bei `attempt = 2`.
     * Warum: Der Backoff muss exponentiell wachsen, sonst hämmert der Worker
     *        im Minutentakt gegen ein totes Storage-Backend.
     * Erwartet: Nachricht mit `attempt = 3` und 120 s Verzögerung.
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
     * Was: Retry-Lauf bei `attempt = MAX_ATTEMPTS` (5) und weiterhin defektem
     *      Storage.
     * Warum: Endlos-Schleife verhindern — der Operator muss erkennen, dass die
     *        Anhänge nicht mehr zugestellt werden können und manuell eingreifen
     *        sollte.
     * Erwartet: `markFailed` als finale Markierung, kein weiterer Dispatch.
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
     * Was: Erschöpfte Versuche (attempt = MAX), aber nur EIN von zwei Anhängen
     *      bleibt dauerhaft unlesbar.
     * Warum: Pauschales `failed` würde suggerieren, dass gar kein Anhang ankam —
     *        tatsächlich wurde ein Teil zugestellt. Die Telemetrie muss den
     *        Teilfehler als `partial_failure` festhalten.
     * Erwartet: `markPartialFailure`, kein `markFailed`, kein Re-Dispatch.
     */
    public function testExhaustedPartialFailureMarksPartial(): void
    {
        $readable = $this->attachment('11111111111111111111111111111111');
        $broken = $this->attachment('22222222222222222222222222222222');
        $broken->setMedia(null); // media == null zählt in countUnreadable als Fehler

        $tracker = $this->createMock(MailAttachmentStatusTrackerInterface::class);
        $tracker->expects(self::once())->method('markPartialFailure')->with(self::ORDER_ID);
        $tracker->expects(self::never())->method('markFailed');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        // storageBroken=false → das lesbare Media wird gelesen; nur $broken (media=null) fällt durch.
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
