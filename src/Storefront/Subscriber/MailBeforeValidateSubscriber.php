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
use Ruhrcoder\RcOrderAttachment\Service\Mail\ShopAttachmentMail;
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
 * Hängt die Uploads einer Bestellung an die Mail an den Shop ({@see ShopAttachmentMail}), sonst an keine Mail.
 *
 * Bis 1.0.12 bekam die Bestellbestätigung die Dateien und damit auch der Kunde. Seit 1.1.0 geht die Bestätigung ohne
 * Anhang hinaus, und der Shop bekommt eine eigene Mail. Angehängt wird nur, wenn der Vorlagentyp der Mail der des Shops
 * ist und die Vorlagendaten es erlauben; sind die Dateien zu groß, nennt die Mail sie nur.
 *
 * Shopware gibt im Ereignis nur die `templateId` mit. Der {@see MailTemplateTypeResolverInterface} ermittelt daraus
 * den `technicalName`.
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

        $technicalName = $this->templateTypeResolver->resolveTechnicalName(
            $this->extractTemplateId($event->getData()),
            $event->getContext(),
        );
        $shopMail = $event->getTemplateData()[ShopAttachmentMail::DATA_KEY] ?? null;
        if ($technicalName !== ShopAttachmentMail::TEMPLATE_TYPE || !\is_array($shopMail) || ($shopMail['attach'] ?? false) !== true) {
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
