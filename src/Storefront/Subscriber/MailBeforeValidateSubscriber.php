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
 * Hängt die zur Bestellung gehörenden Customer-Uploads an konfigurierte
 * Mail-Templates (Default: `order_confirmation_mail`).
 *
 * Drei Gates müssen passieren:
 *   1. Template-Daten enthalten eine `order`
 *   2. Plugin aktiv UND `attachToConfirmationMail` aktiv
 *   3. Mail-Template-Technical-Name in der Whitelist (oder Whitelist leer = alle)
 *
 * Die Whitelist verhindert, dass Anhänge bei jeder Order-State-Change-Mail
 * (Versand, Storno, Rückerstattung) erneut mitgeschickt werden — sonst würde
 * eine Bestellung mit 20 MB Anhängen die Mitarbeiter-Mailbox sprengen.
 *
 * Gate 3 im Detail: Shopware liefert im Event KEINEN `mailTemplate`-Key in den
 * Template-Daten — nur `eventName` (Flow-Event) und in den Mail-Daten die
 * `templateId`. Der {@see MailTemplateTypeResolverInterface} löst daraus den
 * `technicalName` auf. Wird nur gegen `eventName` geprüft, matcht der
 * Default-Whitelist-Wert `order_confirmation_mail` niemals und es geht kein
 * einziger Anhang raus.
 *
 * Resultat des Mail-Anhang-Versuchs landet als Marker im Custom-Field
 * `rc_order_attachment_mail_status` (siehe {@see MailAttachmentStatusTrackerInterface}).
 * Bei aktivem Opt-in `enableMailRetry` wird bei Teil-/Voll-Fehler ein
 * {@see RetryFailedMailAttachmentsMessage} dispatcht.
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

        // Whitelist-Check: Anhänge nur an konfigurierte Mail-Templates hängen.
        // Schützt vor Mehrfach-Versand bei Order-State-Changes (Versand, Storno).
        //
        // Zwei Kandidaten, weil Shopware im Event nur die `templateId` mitgibt:
        //   1. aufgelöster Mail-Template-Type (`order_confirmation_mail`) — der Default
        //   2. Flow-Event-Name (`checkout.order.placed`) — falls ein Admin ihn einträgt
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
            $existing = $data[self::DATA_KEY_BIN_ATTACHMENTS] ?? [];
            if (!\is_array($existing)) {
                $existing = [];
            }

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
     * Die ID des verwendeten Mail-Templates liegt in den Mail-Daten (`SendMailAction`
     * setzt sie über `$data->set('templateId', ...)`), nicht in den Template-Daten.
     *
     * @param array<string, mixed> $data
     */
    private function extractTemplateId(array $data): ?string
    {
        $templateId = $data['templateId'] ?? null;

        return \is_string($templateId) && $templateId !== '' ? $templateId : null;
    }

    /**
     * Flow-Event-Name, z. B. `checkout.order.placed`. `SendMailAction` legt ihn
     * als `eventName` in die Template-Daten.
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

        $content = $context->scope(Context::SYSTEM_SCOPE, fn (Context $ctx): string => $this->mediaService->loadFile($media->getId(), $ctx));

        return [
            'content' => $content,
            'fileName' => $attachment->getOriginalFileName(),
            'mimeType' => $attachment->getMimeType() !== '' ? $attachment->getMimeType() : 'application/octet-stream',
        ];
    }
}
