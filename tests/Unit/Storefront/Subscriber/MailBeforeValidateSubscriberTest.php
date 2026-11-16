<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\Mail\MailAttachmentStatusTrackerInterface;
use Ruhrcoder\RcOrderAttachment\Service\Mail\MailTemplateTypeResolverInterface;
use Ruhrcoder\RcOrderAttachment\Service\Mail\ShopAttachmentMail;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use Ruhrcoder\RcOrderAttachment\Storefront\Subscriber\MailBeforeValidateSubscriber;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Prüft den Subscriber, der die Kundendateien an die Bestätigungsmail hängt: die drei
 * Vorbedingungen (Bestellung vorhanden, Schalter an, Vorlage freigegeben), das Ergänzen von
 * `binAttachments`, den Status `attached`, `partial_failure` oder `failed` und den zuschaltbaren
 * Wiederholungsversuch über `enableMailRetry`. Hängt hier etwas falsch, bekommt der Support die
 * Datei nicht oder jede Statusmail trägt sie erneut.
 */
final class MailBeforeValidateSubscriberTest extends TestCase
{
    private const ORDER_ID = '11111111111111111111111111111111';

    /** Echte Flow-Ereignisnamen; als `eventName` setzt Shopware nie den Namen eines Vorlagentyps. */
    private const EVENT_ORDER_PLACED = 'checkout.order.placed';

    private const EVENT_SHIPPED = 'state_enter.order_delivery.state.shipped';

    private const TEMPLATE_ID = '22222222222222222222222222222222';

    private const TYPE_ORDER_CONFIRMATION = 'order_confirmation_mail';

    private const TYPE_SHOP = ShopAttachmentMail::TEMPLATE_TYPE;

    /**
     * Erste Vorbedingung: Mails ohne Bestellung, etwa ein Newsletter, bekommen keine Anhänge.
     */
    public function testSkipsWhenNoOrderInTemplateData(): void
    {
        $sub = $this->subscriber(
            attachments: [],
            configOverrides: [],
        );

        $event = new MailBeforeValidateEvent([], Context::createDefaultContext(), ['eventName' => 'newsletter']);
        $sub->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $event->getData());
    }

    /**
     * Zweite Vorbedingung: Ist der Mailanhang abgeschaltet, etwa aus Datenschutzgründen oder
     * wegen voller Postfächer, steigt der Subscriber vor jeder Datenbankabfrage aus.
     */
    public function testSkipsWhenPluginOrMailToggleDisabled(): void
    {
        // Erst dass Repository und MediaService unberührt bleiben, belegt den frühen Ausstieg.
        // Ohne diese Erwartung bestünde der Test auch, wenn der Subscriber den vollen Weg ginge
        // und nur zufällig nichts fände.
        $sub = $this->subscriberWithUntouchedLoader(['attachToConfirmationMail' => false]);

        $event = new MailBeforeValidateEvent([], Context::createDefaultContext(), ['order' => $this->order()]);
        $sub->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $event->getData());
    }

    /**
     * An `partial_failure` erkennt der Betrieb, dass eine Mail unvollständig hinausging. Würde
     * daraus still `failed` oder `attached`, sähe der Support Alarm ohne Anlass oder gar nichts.
     * Der intakte Anhang geht trotzdem mit.
     */
    public function testMarksPartialFailureWhenOneAttachmentCannotBeLoaded(): void
    {
        $tracker = $this->createMock(MailAttachmentStatusTrackerInterface::class);
        $tracker->expects(self::once())->method('markPartialFailure')->with(self::ORDER_ID);
        $tracker->expects(self::never())->method('markAttached');
        $tracker->expects(self::never())->method('markFailed');

        $sub = $this->subscriber(
            attachments: [
                $this->attachment('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'gut.pdf', 'application/pdf'),
                $this->attachmentWithoutMedia('cccccccccccccccccccccccccccccccc', 'kaputt.pdf'),
            ],
            configOverrides: [],
            statusTracker: $tracker,
            mediaContent: 'BINARY-CONTENT',
            resolvedTechnicalName: self::TYPE_SHOP,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED, ShopAttachmentMail::DATA_KEY => ['attach' => true]],
        );

        $sub->onMailBeforeValidate($event);

        self::assertCount(1, self::binAttachments($event), 'der intakte Anhang muss trotzdem mitgehen');
        self::assertSame('gut.pdf', self::attachmentAt($event, 0)['fileName']);
    }

    /**
     * Gegenstück zum Teilfehlschlag: Scheitert alles, lautet der Status `failed`. Erst beide
     * Tests zusammen halten die Unterscheidung fest, auf die sich der Support verlässt.
     */
    public function testMarksFailedWhenNoAttachmentCanBeLoaded(): void
    {
        $tracker = $this->createMock(MailAttachmentStatusTrackerInterface::class);
        $tracker->expects(self::once())->method('markFailed')->with(self::ORDER_ID);
        $tracker->expects(self::never())->method('markPartialFailure');

        $sub = $this->subscriber(
            attachments: [$this->attachmentWithoutMedia('dddddddddddddddddddddddddddddddd', 'kaputt.pdf')],
            configOverrides: [],
            statusTracker: $tracker,
            resolvedTechnicalName: self::TYPE_SHOP,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED, ShopAttachmentMail::DATA_KEY => ['attach' => true]],
        );

        $sub->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $event->getData());
    }

    /**
     * Der zentrale Fall: Die Bestätigungsmail trägt die Datei, der Status lautet `attached`, und
     * die übrigen Maildaten bleiben unberührt.
     */
    public function testAddsAttachmentsToDataAndMarksAttached(): void
    {
        $tracker = $this->createMock(MailAttachmentStatusTrackerInterface::class);
        $tracker->expects(self::once())->method('markAttached')->with(self::ORDER_ID);

        $sub = $this->subscriber(
            attachments: [$this->attachment('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'foo.pdf', 'application/pdf')],
            configOverrides: [],
            statusTracker: $tracker,
            mediaContent: 'BINARY-CONTENT',
            resolvedTechnicalName: self::TYPE_SHOP,
        );

        $event = new MailBeforeValidateEvent(
            ['someExisting' => 'value', 'templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED, ShopAttachmentMail::DATA_KEY => ['attach' => true]],
        );

        $sub->onMailBeforeValidate($event);

        $data = $event->getData();
        self::assertArrayHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $data);
        self::assertCount(1, self::binAttachments($event));
        self::assertSame('foo.pdf', self::attachmentAt($event, 0)['fileName']);
        self::assertSame('BINARY-CONTENT', self::attachmentAt($event, 0)['content']);
        self::assertSame('value', $data['someExisting'] ?? null);
    }

    /**
     * Kern oder andere Plugins können schon Anhänge eingetragen haben, etwa die Rechnung. Die
     * Kundendatei kommt dazu, statt sie zu ersetzen.
     */
    public function testMergesWithExistingBinAttachments(): void
    {
        $sub = $this->subscriber(
            attachments: [$this->attachment('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'foo.pdf', 'application/pdf')],
            configOverrides: [],
            mediaContent: 'blob',
            resolvedTechnicalName: self::TYPE_SHOP,
        );

        $existing = [
            'templateId' => self::TEMPLATE_ID,
            'binAttachments' => [['content' => 'pdf-from-shopware', 'fileName' => 'invoice.pdf', 'mimeType' => 'application/pdf']],
        ];

        $event = new MailBeforeValidateEvent(
            $existing,
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED, ShopAttachmentMail::DATA_KEY => ['attach' => true]],
        );
        $sub->onMailBeforeValidate($event);

        self::assertCount(2, self::binAttachments($event));
    }

    /**
     * Dritte Vorbedingung: Steht nur `order_confirmation_mail` in der Freigabeliste, bekommt die
     * Versandmail keinen Anhang. Sonst hinge jede Statusmail die Dateien erneut an.
     */
    public function testSkipsWhenTemplateNotInWhitelist(): void
    {
        $sub = $this->subscriber(
            attachments: [],
            configOverrides: ['mailTemplateWhitelist' => self::TYPE_ORDER_CONFIRMATION],
            resolvedTechnicalName: 'order_delivery.state.shipped',
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_SHIPPED],
        );

        $sub->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $event->getData());
    }

    /**
     * So sieht es im Betrieb aus: Vorgabe `order_confirmation_mail`, als `eventName` steht
     * `checkout.order.placed`, und der Vorlagentyp kommt erst über `data['templateId']`. Wer sich
     * auf `templateData['mailTemplate']` verlässt (gibt es in Shopware 6.7 nicht) und auf
     * `eventName` zurückfällt, findet nie einen Treffer, und kein Anhang geht hinaus.
     */
    public function testAttachesWithDefaultWhitelistWhenTemplateTypeResolves(): void
    {
        $sub = $this->subscriber(
            attachments: [$this->attachment('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', 'zeichnung.pdf', 'application/pdf')],
            configOverrides: ['mailTemplateWhitelist' => self::TYPE_ORDER_CONFIRMATION],
            mediaContent: 'PDF-BYTES',
            resolvedTechnicalName: self::TYPE_SHOP,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED, ShopAttachmentMail::DATA_KEY => ['attach' => true]],
        );

        $sub->onMailBeforeValidate($event);

        $data = $event->getData();
        self::assertArrayHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $data);
        self::assertCount(1, self::binAttachments($event));
        self::assertSame('zeichnung.pdf', self::attachmentAt($event, 0)['fileName']);
        self::assertSame('PDF-BYTES', self::attachmentAt($event, 0)['content']);
    }

    /**
     * Lässt sich der Vorlagentyp nicht auflösen und steht auch `eventName` nicht in der Liste,
     * geht kein Anhang mit. Lieber keiner als ein Kundendokument an einer Mail unbekannten Typs.
     */
    public function testSkipsWhenTemplateTypeCannotBeResolved(): void
    {
        $sub = $this->subscriber(
            attachments: [$this->attachment('a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2a2', 'foo.pdf', 'application/pdf')],
            configOverrides: ['mailTemplateWhitelist' => self::TYPE_ORDER_CONFIRMATION],
            mediaContent: 'blob',
            resolvedTechnicalName: null,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED, ShopAttachmentMail::DATA_KEY => ['attach' => true]],
        );

        $sub->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $event->getData());
    }

    /**
     * Was: Die Bestellbestätigung an den Kunden.
     * Warum: BRIEF66. Der Kunde hat die Dateien selbst, und große Anhänge ließen am 09.11.2026 beide Bestellmails scheitern.
     * Erwartet: kein Anhang, auch wenn alles andere passt.
     */
    public function testTheCustomerConfirmationNeverCarriesAttachments(): void
    {
        $sub = $this->subscriber(
            attachments: [$this->attachment('a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3', 'plan.pdf', 'application/pdf')],
            configOverrides: [],
            mediaContent: 'blob',
            resolvedTechnicalName: self::TYPE_ORDER_CONFIRMATION,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED, ShopAttachmentMail::DATA_KEY => ['attach' => true]],
        );

        $sub->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $event->getData());
    }

    /**
     * Was: Die Mail an den Shop, aber die Dateien sind zu groß.
     * Warum: Darüber nennt die Mail sie nur, sonst scheiterte sie selbst an der Größengrenze.
     * Erwartet: kein Anhang.
     */
    public function testTheShopMailCarriesNoAttachmentsWhenTheyAreTooBig(): void
    {
        $sub = $this->subscriber(
            attachments: [$this->attachment('a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4a4', 'plan.pdf', 'application/pdf')],
            configOverrides: [],
            mediaContent: 'blob',
            resolvedTechnicalName: self::TYPE_SHOP,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), ShopAttachmentMail::DATA_KEY => ['attach' => false]],
        );

        $sub->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $event->getData());
    }

    /**
     * Der Status lautet `failed`, damit der Betrieb es sieht. Mit `enableMailRetry` geht
     * zusätzlich ein Wiederholungsauftrag in die Warteschlange, weil ein kurzer Speicherausfall
     * von selbst vorbeigehen kann.
     */
    public function testFailedAttachmentMarksFailureAndDispatchesRetryWhenEnabled(): void
    {
        $tracker = $this->createMock(MailAttachmentStatusTrackerInterface::class);
        $tracker->expects(self::once())->method('markFailed')->with(self::ORDER_ID);
        $tracker->expects(self::never())->method('markAttached');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willReturn(new Envelope(new stdClass()));

        // Ohne MediaEntity wirft `buildOne()` eine `MediaNotLoadedException`.
        $brokenAttachment = $this->attachmentWithoutMedia('cccccccccccccccccccccccccccccccc', 'broken.pdf');

        $sub = $this->subscriber(
            attachments: [$brokenAttachment],
            configOverrides: ['enableMailRetry' => true],
            statusTracker: $tracker,
            messageBus: $bus,
            resolvedTechnicalName: self::TYPE_SHOP,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED, ShopAttachmentMail::DATA_KEY => ['attach' => true]],
        );
        $sub->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $event->getData());
    }

    /**
     * Die Wiederholung ist zuschaltbar und standardmäßig aus, etwa wegen der Gefahr doppelter
     * Mails. Ohne Schalter geht nichts in die Warteschlange.
     */
    public function testFailedAttachmentMarksFailureButDoesNotDispatchWhenOptOut(): void
    {
        $tracker = $this->createMock(MailAttachmentStatusTrackerInterface::class);
        $tracker->expects(self::once())->method('markFailed')->with(self::ORDER_ID);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $sub = $this->subscriber(
            attachments: [$this->attachmentWithoutMedia('dddddddddddddddddddddddddddddddd', 'broken.pdf')],
            configOverrides: ['enableMailRetry' => false],
            statusTracker: $tracker,
            messageBus: $bus,
            resolvedTechnicalName: self::TYPE_SHOP,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED, ShopAttachmentMail::DATA_KEY => ['attach' => true]],
        );
        $sub->onMailBeforeValidate($event);
    }

    /**
     * @param list<OrderAttachmentEntity> $attachments
     * @param array<string, mixed>        $configOverrides
     */
    private function subscriber(
        array $attachments,
        array $configOverrides,
        ?MailAttachmentStatusTrackerInterface $statusTracker = null,
        ?MessageBusInterface $messageBus = null,
        string $mediaContent = '',
        ?string $resolvedTechnicalName = null,
    ): MailBeforeValidateSubscriber {
        $loader = $this->attachmentLoader($attachments);

        $mediaService = $this->createMock(MediaService::class);
        $mediaService->method('loadFile')->willReturn($mediaContent);

        return new MailBeforeValidateSubscriber(
            $loader,
            $mediaService,
            $this->configProvider($configOverrides),
            $statusTracker ?? $this->createMock(MailAttachmentStatusTrackerInterface::class),
            $this->templateTypeResolver($resolvedTechnicalName),
            $messageBus ?? $this->createMock(MessageBusInterface::class),
            new NullLogger(),
        );
    }

    /**
     * Eine Attrappe statt eines Mocks, weil der Resolver genau eine Methode hat und ihr
     * Rückgabewert der ganze Vertrag ist. `null` heißt: Abfrage gescheitert oder keine `templateId`.
     */
    private function templateTypeResolver(?string $technicalName): MailTemplateTypeResolverInterface
    {
        return new class ($technicalName) implements MailTemplateTypeResolverInterface {
            public function __construct(private readonly ?string $technicalName)
            {
            }

            public function resolveTechnicalName(?string $mailTemplateId, Context $context): ?string
            {
                return $mailTemplateId === null ? null : $this->technicalName;
            }
        };
    }

    /**
     * Subscriber, dessen Anhangsrepository nie befragt werden darf.
     *
     * Prüfte ein Test nur, dass `binAttachments` fehlt, bestünde er auch, wenn der Subscriber den
     * teuren Weg ginge und am Ende nichts fände. Erst die Erwartung „nie aufgerufen" belegt den
     * frühen Ausstieg.
     *
     * @param array<string, mixed> $configOverrides
     */
    private function subscriberWithUntouchedLoader(array $configOverrides): MailBeforeValidateSubscriber
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('search');

        $mediaService = $this->createMock(MediaService::class);
        $mediaService->expects(self::never())->method('loadFile');

        return new MailBeforeValidateSubscriber(
            new OrderAttachmentLoader($repository),
            $mediaService,
            $this->configProvider($configOverrides),
            $this->createMock(MailAttachmentStatusTrackerInterface::class),
            $this->templateTypeResolver(self::TYPE_SHOP),
            $this->createMock(MessageBusInterface::class),
            new NullLogger(),
        );
    }

    /**
     * @param array<OrderAttachmentEntity> $entities
     */
    private function attachmentLoader(array $entities): OrderAttachmentLoader
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

    /**
     * @param array<string, mixed> $overrides
     */
    private function configProvider(array $overrides): PluginConfigProvider
    {
        $defaults = [
            'enabled' => true,
            'required' => false,
            'maxFiles' => 5,
            'maxFileSizeKb' => 1024,
            'maxTotalSizeKb' => 4096,
            'allowedExtensions' => 'pdf',
            'orphanRetentionHours' => 24,
            'attachmentRetentionDays' => 180,
            'attachToConfirmationMail' => true,
            'stripExifFromImages' => true,
            'rateLimitPerMinute' => 30,
            // Die echte Vorgabe der Auslieferung. Ein leerer Text wäre irreführend, weil
            // `PluginConfigProvider::readString()` ihn ohnehin durch genau diesen Wert ersetzt.
            'mailTemplateWhitelist' => self::TYPE_ORDER_CONFIRMATION,
            'enableMailRetry' => false,
        ];
        $values = [...$defaults, ...$overrides];

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key) => $values[substr($key, \strlen(PluginConfigProvider::DOMAIN))] ?? null,
        );

        return new PluginConfigProvider($systemConfig);
    }

    /**
     * Die Anhänge, die der Subscriber an die Mail gehängt hat.
     *
     * @return array<mixed>
     */
    private static function binAttachments(MailBeforeValidateEvent $event): array
    {
        $attachments = $event->getData()[MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS] ?? null;
        self::assertIsArray($attachments);

        return $attachments;
    }

    /**
     * @return array<mixed>
     */
    private static function attachmentAt(MailBeforeValidateEvent $event, int $index): array
    {
        $attachment = self::binAttachments($event)[$index] ?? null;
        self::assertIsArray($attachment);

        return $attachment;
    }

    private function order(): OrderEntity
    {
        $order = new OrderEntity();
        $order->setId(self::ORDER_ID);
        $order->setUniqueIdentifier(self::ORDER_ID);
        $order->setSalesChannelId('sc-1');

        return $order;
    }

    private function attachment(string $id, string $name, string $mime): OrderAttachmentEntity
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
        $entity->setOriginalFileName($name);
        $entity->setMimeType($mime);
        $entity->setFileSize(100);
        $entity->setMedia($media);

        return $entity;
    }

    private function attachmentWithoutMedia(string $id, string $name): OrderAttachmentEntity
    {
        $entity = new OrderAttachmentEntity();
        $entity->setId($id);
        $entity->setUniqueIdentifier($id);
        $entity->setOrderId(self::ORDER_ID);
        $entity->setOrderVersionId('00000000000000000000000000000000');
        $entity->setMediaId('ffffffffffffffffffffffffffffffff');
        $entity->setOriginalFileName($name);
        $entity->setMimeType('application/pdf');
        $entity->setFileSize(100);

        return $entity;
    }
}
