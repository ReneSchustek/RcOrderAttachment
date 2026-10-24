<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Message\RetryFailedMailAttachmentsMessage;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\Exception\MediaNotLoadedException;
use Ruhrcoder\RcOrderAttachment\Service\Mail\MailAttachmentStatusTrackerInterface;
use Ruhrcoder\RcOrderAttachment\Service\Mail\MailTemplateTypeResolverInterface;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

/**
 * Hängt die Uploads einer Bestellung an die freigegebenen Mailvorlagen, als Vorgabe nur an
 * `order_confirmation_mail`.
 *
 * Angehängt wird nur, wenn die Vorlagendaten eine Bestellung enthalten, Plugin und
 * `attachToConfirmationMail` eingeschaltet sind und die Vorlage in der Liste erlaubter Vorlagen
 * steht (eine leere Liste erlaubt alle). Die Liste verhindert, dass jede Statusmail zur Bestellung
 * (Versand, Storno, Erstattung) die Dateien erneut mitschickt und eine Bestellung mit 20 MB
 * Anhängen jedes Postfach mehrfach füllt.
 *
 * Shopware gibt im Ereignis keinen Schlüssel `mailTemplate` mit, sondern nur `eventName` in den
 * Vorlagendaten und `templateId` in den Maildaten. Der {@see MailTemplateTypeResolverInterface}
 * ermittelt daraus den `technicalName`. Würde nur `eventName` geprüft, träfe die Vorgabe
 * `order_confirmation_mail` nie, und kein Anhang ginge hinaus.
 *
 * Das Ergebnis landet im Zusatzfeld `rc_order_attachment_mail_status` (siehe
 * {@see MailAttachmentStatusTrackerInterface}). Ist `enableMailRetry` eingeschaltet, plant ein
 * ganzer oder teilweiser Fehlschlag eine {@see RetryFailedMailAttachmentsMessage} ein.
 */
final class MailBeforeValidateSubscriber implements EventSubscriberInterface
{
    public const DATA_KEY_BIN_ATTACHMENTS = 'binAttachments';

    public function __construct(
        private readonly OrderAttachmentLoader $loader,
        private readonly MediaService $mediaService,
        private readonly PluginConfigProvider $configProvider,
        private readonly MailAttachmentStatusTrackerInterface $statusTracker,
        private readonly MailTemplateTypeResolverInterface $templateTypeResolver,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MailBeforeValidateEvent::class => 'onMailBeforeValidate',
        ];
    }

    public function onMailBeforeValidate(MailBeforeValidateEvent $event): void
    {
        $order = $this->extractOrder($event->getTemplateData());
        if (!$order instanceof OrderEntity) {
            return;
        }

        $salesChannelId = $order->getSalesChannelId();
        $config = $this->configProvider->getForSalesChannel($salesChannelId);
        if (!$config->enabled || !$config->attachToConfirmationMail) {
            return;
        }

        // Zwei Kandidaten, weil Shopware im Ereignis nur die `templateId` mitgibt: der daraus
        // ermittelte Vorlagentyp (`order_confirmation_mail`, die Vorgabe) und der Name des
        // Flow-Ereignisses (`checkout.order.placed`), falls jemand ihn in der Verwaltung einträgt.
        $technicalName = $this->templateTypeResolver->resolveTechnicalName(
            $this->extractTemplateId($event->getData()),
            $event->getContext(),
        );
        $eventName = $this->extractEventName($event->getTemplateData());

        if (!$config->isMailTemplateAllowed($technicalName, $eventName)) {
            $this->logger->debug('rc_order_attachment.mail.template_not_whitelisted', [
                'orderId' => $order->getId(),
                'technicalName' => $technicalName,
                'eventName' => $eventName,
            ]);

            return;
        }

        $attachments = $this->loader->loadForOrder($order->getId(), $event->getContext());
        if ($attachments->count() === 0) {
            return;
        }

        [$bin, $failures] = $this->buildBinAttachments($attachments->getElements(), $event->getContext(), $order->getId());
        if ($bin === [] && $failures === 0) {
            return;
        }

        if ($bin !== []) {
            $data = $event->getData();
            // Ab 6.7.15 beschreibt der Kern das Feld als Liste; ältere 6.7-Fassungen nur als `mixed`.
            $existing = $data[self::DATA_KEY_BIN_ATTACHMENTS] ?? null;
            $existing = \is_array($existing) ? $existing : [];

            // Anhänge anderer Plugins oder des Kerns bleiben erhalten; die eigenen kommen dazu.

            $data[self::DATA_KEY_BIN_ATTACHMENTS] = array_merge($existing, $bin);
            $event->setData($data);

            $this->logger->info('rc_order_attachment.mail.attached', [
                'orderId' => $order->getId(),
                'count' => \count($bin),
                'failures' => $failures,
            ]);
        }

        $this->recordOutcome($order->getId(), \count($bin), $failures, $config->enableMailRetry, $event->getContext());
    }

    private function recordOutcome(string $orderId, int $successCount, int $failureCount, bool $retryEnabled, Context $context): void
    {
        if ($failureCount === 0) {
            $this->statusTracker->markAttached($orderId, $context);

            return;
        }

        if ($successCount === 0) {
            $this->statusTracker->markFailed($orderId, $context);
        } else {
            $this->statusTracker->markPartialFailure($orderId, $context);
        }

        if ($retryEnabled) {
            $this->messageBus->dispatch(new RetryFailedMailAttachmentsMessage($orderId));
        }
    }

    /**
     * @param array<string, mixed> $templateData
     */
    private function extractOrder(array $templateData): ?OrderEntity
    {
        $order = $templateData['order'] ?? null;

        return $order instanceof OrderEntity ? $order : null;
    }

    /**
     * Die ID der verwendeten Mailvorlage liegt in den Maildaten (`SendMailAction` setzt sie über
     * `$data->set('templateId', ...)`), nicht in den Vorlagendaten.
     *
     * @param array<string, mixed> $data
     */
    private function extractTemplateId(array $data): ?string
    {
        $templateId = $data['templateId'] ?? null;

        return \is_string($templateId) && $templateId !== '' ? $templateId : null;
    }

    /**
     * Name des Flow-Ereignisses, etwa `checkout.order.placed`, den `SendMailAction` als
     * `eventName` in die Vorlagendaten legt.
     *
     * @param array<string, mixed> $templateData
     */
    private function extractEventName(array $templateData): ?string
    {
        $eventName = $templateData['eventName'] ?? null;

        return \is_string($eventName) && $eventName !== '' ? $eventName : null;
    }

    /**
     * @param array<OrderAttachmentEntity> $attachments
     *
     * @return array{0: list<array{content: string, fileName: string, mimeType: string}>, 1: int}
     */
    private function buildBinAttachments(array $attachments, Context $context, string $orderId): array
    {
        $bin = [];
        $failures = 0;

        foreach ($attachments as $attachment) {
            try {
                $bin[] = $this->buildOne($attachment, $context);
            } catch (Throwable $exception) {
                ++$failures;
                $this->logger->warning('rc_order_attachment.mail.attach_failed', [
                    'orderId' => $orderId,
                    'attachmentId' => $attachment->getId(),
                    'mediaId' => $attachment->getMediaId(),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [$bin, $failures];
    }

    /**
     * @return array{content: string, fileName: string, mimeType: string}
     */
    private function buildOne(OrderAttachmentEntity $attachment, Context $context): array
    {
        $media = $attachment->getMedia();
        if (!$media instanceof MediaEntity) {
            throw new MediaNotLoadedException($attachment->getMediaId());
        }

        // Das Medium ist privat; lesen darf es nur der Systemkontext. Die Datei wandert ganz in den
        // Speicher, weil der Mailer den Inhalt als Zeichenkette erwartet.
        $content = $context->scope(Context::SYSTEM_SCOPE, fn (Context $ctx): string => $this->mediaService->loadFile($media->getId(), $ctx));

        return [
            'content' => $content,
            'fileName' => $attachment->getOriginalFileName(),
            'mimeType' => $attachment->getMimeType() !== '' ? $attachment->getMimeType() : 'application/octet-stream',
        ];
    }
}
