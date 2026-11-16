<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\MessageHandler;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Message\SendShopAttachmentMailMessage;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\Mail\ShopAttachmentMail;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use RuntimeException;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\MailTemplate\MailTemplateCollection;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Schickt dem Shop die Dateien einer Bestellung. Die Dateien selbst hängt {@see \Ruhrcoder\RcOrderAttachment\Storefront\Subscriber\MailBeforeValidateSubscriber}
 * an, damit Status und Wiederholung wie bisher an einer Stelle bleiben.
 *
 * Ein Fehler wird nicht geschluckt: Die Warteschlange versucht es dann erneut, die Bestellung ist längst angelegt.
 */
#[AsMessageHandler]
final class SendShopAttachmentMailHandler
{
    /**
     * @param EntityRepository<OrderCollection>        $orderRepository
     * @param EntityRepository<MailTemplateCollection> $mailTemplateRepository
     */
    public function __construct(
        private readonly OrderAttachmentLoader $loader,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly AbstractMailService $mailService,
        private readonly PluginConfigProvider $configProvider,
        private readonly SystemConfigService $systemConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SendShopAttachmentMailMessage $message): void
    {
        $context = Context::createCLIContext();
        $order = $this->orderRepository->search((new Criteria([$message->orderId]))->addAssociation('orderCustomer'), $context)->getEntities()->first();
        if (!$order instanceof OrderEntity) {
            return;
        }

        $channel = $order->getSalesChannelId();
        $config = $this->configProvider->getForSalesChannel($channel);
        $attachments = $this->loader->loadForOrder($order->getId(), $context);
        if (!$config->enabled || !$config->attachToConfirmationMail || $attachments->count() === 0) {
            return;
        }

        $recipient = ShopAttachmentMail::recipient($config->shopMailRecipient, $this->systemConfig->getString('core.basicInformation.email', $channel));
        if ($recipient === null) {
            $this->logger->warning('rc_order_attachment.shop_mail.no_recipient', ['orderId' => $order->getId()]);

            return;
        }

        $files = array_map(static fn (OrderAttachmentEntity $attachment): array => [
            'name' => $attachment->getOriginalFileName(),
            'size' => round($attachment->getFileSize() / 1048576, 1),
        ], array_values($attachments->getElements()));
        $total = array_sum(array_map(static fn (OrderAttachmentEntity $attachment): int => $attachment->getFileSize(), $attachments->getElements()));
        $attach = ShopAttachmentMail::fitsIntoMail($total, $config->shopMailMaxSizeKb);

        $template = $this->template($context);
        $this->mailService->send([
            'recipients' => [$recipient => $recipient],
            'senderName' => $template->getTranslation('senderName'),
            'salesChannelId' => $channel,
            'subject' => $template->getTranslation('subject'),
            'contentHtml' => $template->getTranslation('contentHtml'),
            'contentPlain' => $template->getTranslation('contentPlain'),
            'templateId' => $template->getId(),
        ], $context, [
            'order' => $order,
            ShopAttachmentMail::DATA_KEY => [
                'attach' => $attach,
                'files' => $files,
                'totalMb' => round($total / 1048576, 1),
                'adminUrl' => rtrim((string) EnvironmentHelper::getVariable('APP_URL', ''), '/') . '/admin#/sw/order/detail/' . $order->getId(),
            ],
        ]);

        $this->logger->info('rc_order_attachment.shop_mail.sent', ['orderId' => $order->getId(), 'attached' => $attach, 'files' => \count($files)]);
    }

    private function template(Context $context): MailTemplateEntity
    {
        $criteria = (new Criteria())->addFilter(new EqualsFilter('mailTemplateType.technicalName', ShopAttachmentMail::TEMPLATE_TYPE))->setLimit(1);
        $template = $this->mailTemplateRepository->search($criteria, $context)->getEntities()->first();
        if (!$template instanceof MailTemplateEntity) {
            throw new RuntimeException('Mail template ' . ShopAttachmentMail::TEMPLATE_TYPE . ' is missing.');
        }

        return $template;
    }
}
