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
 * Unit-Tests für den Mail-Anhang-Subscriber. Prüft alle drei Gates
 * (Order-Vorhandensein, Plugin-/Mail-Toggle, Whitelist-Template), das
 * Hängen + Merging der `binAttachments`-Daten, das Status-Tracking
 * (`attached` / `partial_failure` / `failed`) und den optionalen
 * Retry-Dispatch via `enableMailRetry`.
 */
final class MailBeforeValidateSubscriberTest extends TestCase
{
    private const ORDER_ID = '11111111111111111111111111111111';

    /** Reale Flow-Event-Namen — Shopware setzt NIE einen Template-Type-Namen als `eventName`. */
    private const EVENT_ORDER_PLACED = 'checkout.order.placed';

    private const EVENT_SHIPPED = 'state_enter.order_delivery.state.shipped';

    private const TEMPLATE_ID = '22222222222222222222222222222222';

    private const TYPE_ORDER_CONFIRMATION = 'order_confirmation_mail';

    /**
     * Was: Template-Daten enthalten keine `order`.
     * Warum: Erste Gate-Stufe — Mail-Templates ohne Order-Kontext (z. B.
     *        Newsletter) dürfen keine Order-Anhänge bekommen.
     * Erwartet: Kein `binAttachments`-Eintrag im Event-Daten-Array.
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
     * Was: Plugin-Config hat `attachToConfirmationMail = false`.
     * Warum: Zweite Gate-Stufe — Operator hat den Mail-Anhang explizit
     *        ausgeschaltet (z. B. Datenschutz-Wunsch oder Mailbox-Overflow-
     *        Vermeidung). Subscriber muss früh raus, kein DAL-Roundtrip.
     * Erwartet: Kein `binAttachments`-Eintrag, kein `attach`-Versuch.
     */
    public function testSkipsWhenPluginOrMailToggleDisabled(): void
    {
        // Repository und MediaService dürfen nicht angefasst werden — das belegt den frühen
        // Ausstieg. Ohne diese Erwartung bestände der Test auch, wenn der Subscriber den vollen
        // Weg ginge und nur zufällig nichts fände.
        $sub = $this->subscriberWithUntouchedLoader(['attachToConfirmationMail' => false]);

        $event = new MailBeforeValidateEvent([], Context::createDefaultContext(), ['order' => $this->order()]);
        $sub->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $event->getData());
    }

    /**
     * Was: Zwei Anhänge, einer davon ohne geladenes Media.
     * Warum: Der `markPartialFailure`-Zweig war ungetestet. Er trägt die Telemetrie, an der der
     *        Betrieb erkennt, dass eine Mail unvollständig rausging — wird er still zu
     *        `markFailed` oder `markAttached`, sieht der Support entweder Alarm ohne Anlass oder
     *        gar nichts.
     * Erwartet: Der intakte Anhang hängt an der Mail, `markPartialFailure` wird genau einmal
     *           gerufen, `markAttached` und `markFailed` nicht.
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
            resolvedTechnicalName: self::TYPE_ORDER_CONFIRMATION,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED],
        );

        $sub->onMailBeforeValidate($event);

        $bin = $event->getData()[MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS];
        self::assertCount(1, $bin, 'der intakte Anhang muss trotzdem mitgehen');
        self::assertSame('gut.pdf', $bin[0]['fileName']);
    }

    /**
     * Was: Ein einziger Anhang, dessen Media nicht geladen ist.
     * Warum: Gegenstück zum Teil-Fehlschlag — schlägt alles fehl, muss `markFailed` greifen.
     *        Beide Zweige zusammen nageln die Unterscheidung fest, auf die sich der Support
     *        verlässt.
     * Erwartet: `markFailed` einmal, kein `binAttachments`-Eintrag.
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
            resolvedTechnicalName: self::TYPE_ORDER_CONFIRMATION,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED],
        );

        $sub->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $event->getData());
    }

    /**
     * Was: Happy-Path mit einem geladenen Anhang und Default-Konfiguration.
     * Warum: Der zentrale Use-Case — Bestätigungs-Mail soll die Datei
     *        binär anhängen UND den Status auf `attached` schreiben (Telemetrie).
     * Erwartet: `binAttachments[0]` enthält Filename + Content, bestehende
     *           Mail-Daten bleiben erhalten, `markAttached` wird einmal gerufen.
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
            resolvedTechnicalName: self::TYPE_ORDER_CONFIRMATION,
        );

        $event = new MailBeforeValidateEvent(
            ['someExisting' => 'value', 'templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED],
        );

        $sub->onMailBeforeValidate($event);

        $data = $event->getData();
        self::assertArrayHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $data);
        self::assertCount(1, $data[MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS]);
        self::assertSame('foo.pdf', $data[MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS][0]['fileName']);
        self::assertSame('BINARY-CONTENT', $data[MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS][0]['content']);
        self::assertSame('value', $data['someExisting']);
    }

    /**
     * Was: Event hat bereits einen `binAttachments`-Eintrag (z. B. Rechnung
     *      aus Shopware-Standard).
     * Warum: Andere Plugins/Shopware-Core könnten bereits Mail-Anhänge
     *        hinzugefügt haben. Wir dürfen sie nicht überschreiben — Merging
     *        statt Replace.
     * Erwartet: `binAttachments` enthält zwei Einträge nach dem Run.
     */
    public function testMergesWithExistingBinAttachments(): void
    {
        $sub = $this->subscriber(
            attachments: [$this->attachment('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'foo.pdf', 'application/pdf')],
            configOverrides: [],
            mediaContent: 'blob',
            resolvedTechnicalName: self::TYPE_ORDER_CONFIRMATION,
        );

        $existing = [
            'templateId' => self::TEMPLATE_ID,
            'binAttachments' => [['content' => 'pdf-from-shopware', 'fileName' => 'invoice.pdf', 'mimeType' => 'application/pdf']],
        ];

        $event = new MailBeforeValidateEvent(
            $existing,
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED],
        );
        $sub->onMailBeforeValidate($event);

        self::assertCount(2, $event->getData()['binAttachments']);
    }

    /**
     * Was: `eventName` ist `state_enter.order_delivery.state.shipped`,
     *      Whitelist enthält nur `order_confirmation_mail`.
     * Warum: Dritte Gate-Stufe — Whitelist verhindert, dass die Anhänge bei
     *        Versand-/Storno-Mails erneut mitgehängt werden. Sonst würde jede
     *        Order-State-Change-Mail die Mitarbeiter-Mailbox sprengen.
     * Erwartet: Kein `binAttachments`-Eintrag.
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
     * Was: Produktiv-Realität — Default-Whitelist `order_confirmation_mail`,
     *      `eventName` ist der Flow-Event-Name `checkout.order.placed`, der
     *      Template-Type wird über `data['templateId']` aufgelöst.
     * Warum: REGRESSION. Vorher prüfte der Subscriber nur `templateData['mailTemplate']`
     *        (existiert in Shopware 6.7 nicht) mit Fallback auf `eventName`. Damit
     *        verglich er `checkout.order.placed` gegen `order_confirmation_mail` —
     *        kein Treffer, und es ging NIE ein Anhang an die Bestätigungs-Mail.
     * Erwartet: Genau ein `binAttachments`-Eintrag mit Dateiname und Inhalt.
     */
    public function testAttachesWithDefaultWhitelistWhenTemplateTypeResolves(): void
    {
        $sub = $this->subscriber(
            attachments: [$this->attachment('a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1', 'zeichnung.pdf', 'application/pdf')],
            configOverrides: ['mailTemplateWhitelist' => self::TYPE_ORDER_CONFIRMATION],
            mediaContent: 'PDF-BYTES',
            resolvedTechnicalName: self::TYPE_ORDER_CONFIRMATION,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED],
        );

        $sub->onMailBeforeValidate($event);

        $data = $event->getData();
        self::assertArrayHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $data);
        self::assertCount(1, $data[MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS]);
        self::assertSame('zeichnung.pdf', $data[MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS][0]['fileName']);
        self::assertSame('PDF-BYTES', $data[MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS][0]['content']);
    }

    /**
     * Was: Der Template-Type lässt sich nicht auflösen (kein `templateId`,
     *      gelöschtes Template oder DB-Fehler) und der `eventName` steht
     *      nicht in der Whitelist.
     * Warum: Fail-closed. Lieber kein Anhang als ein Kundendokument an einer
     *        Mail, deren Typ wir nicht kennen (z. B. Storno an Dritte).
     * Erwartet: Kein `binAttachments`-Eintrag.
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
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED],
        );

        $sub->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $event->getData());
    }

    /**
     * Was: Admin trägt statt des Template-Type-Namens den Flow-Event-Namen
     *      `checkout.order.placed` in die Whitelist ein.
     * Warum: Das Admin-Feld ist Freitext. Beide Schreibweisen sollen greifen,
     *        damit eine plausible Eingabe nicht stillschweigend wirkungslos ist.
     * Erwartet: Anhang wird gehängt, obwohl der Resolver nichts liefert.
     */
    public function testAcceptsFlowEventNameAsWhitelistEntry(): void
    {
        $sub = $this->subscriber(
            attachments: [$this->attachment('a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3a3', 'plan.pdf', 'application/pdf')],
            configOverrides: ['mailTemplateWhitelist' => self::EVENT_ORDER_PLACED],
            mediaContent: 'blob',
            resolvedTechnicalName: null,
        );

        $event = new MailBeforeValidateEvent(
            [],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED],
        );

        $sub->onMailBeforeValidate($event);

        self::assertCount(1, $event->getData()[MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS]);
    }

    /**
     * Was: Anhang ohne Media-Entity (verlorene Assoziation), `enableMailRetry = true`.
     * Warum: Status muss als `failed` markiert werden, damit der Operator es
     *        sieht. Zusätzlich wird der Retry-Handler über die Queue angestoßen,
     *        weil der Storage-Hiccup eventuell von selbst heilt.
     * Erwartet: `markFailed` einmal, `markAttached` nie, Message-Dispatch
     *           einmal mit `RetryFailedMailAttachmentsMessage`.
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

        // Attachment ohne MediaEntity → buildOne wirft MediaNotLoadedException.
        $brokenAttachment = $this->attachmentWithoutMedia('cccccccccccccccccccccccccccccccc', 'broken.pdf');

        $sub = $this->subscriber(
            attachments: [$brokenAttachment],
            configOverrides: ['enableMailRetry' => true],
            statusTracker: $tracker,
            messageBus: $bus,
            resolvedTechnicalName: self::TYPE_ORDER_CONFIRMATION,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED],
        );
        $sub->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $event->getData());
    }

    /**
     * Was: Anhang fehlgeschlagen, aber `enableMailRetry = false` (Default).
     * Warum: Retry ist Opt-in — ohne aktive Konfiguration darf nichts in die
     *        Queue dispatcht werden (Operator hat sich bewusst gegen Auto-Retry
     *        entschieden, z. B. wegen Mail-Dupe-Risiko).
     * Erwartet: `markFailed` einmal, kein Message-Dispatch.
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
            resolvedTechnicalName: self::TYPE_ORDER_CONFIRMATION,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => self::TEMPLATE_ID],
            Context::createDefaultContext(),
            ['order' => $this->order(), 'eventName' => self::EVENT_ORDER_PLACED],
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
     * Fake statt Mock: der Resolver hat genau eine Methode und der Rückgabewert
     * ist der ganze Vertrag. `null` = Lookup fehlgeschlagen oder keine templateId.
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
     */
    /**
     * Subscriber, dessen Anhang-Repository **nie** befragt werden darf.
     *
     * Ein Gate-Test, der nur die Abwesenheit des `binAttachments`-Schlüssels prüft, ist vakuant:
     * Er bestände auch, wenn der Subscriber den teuren Weg ginge und am Ende nichts fände. Erst
     * die Never-Erwartung belegt den frühen Ausstieg.
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
            $this->templateTypeResolver(self::TYPE_ORDER_CONFIRMATION),
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
            // Bewusst der echte Auslieferungs-Default. Ein leerer String hier wäre
            // irreführend: `PluginConfigProvider::readString()` ersetzt ihn ohnehin
            // durch genau diesen Wert.
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
