<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Integration\Storefront\Subscriber;

use Ruhrcoder\RcOrderAttachment\Service\Mail\MailTemplateTypeResolverInterface;
use Ruhrcoder\RcOrderAttachment\Service\Mail\ShopAttachmentMail;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentManagerInterface;
use Ruhrcoder\RcOrderAttachment\Storefront\Subscriber\MailBeforeValidateSubscriber;
use Ruhrcoder\RcOrderAttachment\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Mime\Email;

/**
 * Durchgehender Nachweis der Kernanforderung: Die vom Kunden hochgeladene Datei hängt an der
 * Bestellbestätigung, damit der Support sie bekommt. Der Test läuft gegen die echte DAL, den
 * echten Medienspeicher und die Mailvorlagen aus der Datenbank.
 *
 * Die Ereignisdaten entsprechen dem, was `SendMailAction` in Shopware 6.7 an
 * `MailService::send()` übergibt: In `data` steht nur `templateId` und kein `mailTemplate`, in
 * `templateData` stehen `eventName` (`checkout.order.placed`) und die Bestellung. Wer den
 * Ereignisnamen statt des aufgelösten Vorlagentyps gegen die Freigabeliste
 * `order_confirmation_mail` vergleicht, findet nie einen Treffer, und kein Anhang geht hinaus.
 */
final class MailAttachmentIntegrationTest extends IntegrationTestCase
{
    private const ATTACHMENT_CONTENT = '%PDF-1.4 rc-order-attachment integration probe';

    /**
     * Ohne Auflösung der `templateId` zum `technicalName` des Vorlagentyps bleibt
     * `binAttachments` leer, und der Support bekommt die Kundendatei nie.
     */
    public function testTheCustomerConfirmationCarriesNoAttachment(): void
    {
        $orderId = $this->createTestOrder();
        $mediaId = $this->createStoredMedia();

        $this->manager()->attach(
            orderId: $orderId,
            mediaId: $mediaId,
            originalFileName: 'kundenzeichnung.pdf',
            mimeType: 'application/pdf',
            fileSize: \strlen(self::ATTACHMENT_CONTENT),
            context: $this->context,
        );

        $event = new MailBeforeValidateEvent(
            // So setzt es `SendMailAction`: `templateId`, kein `mailTemplate`.
            ['templateId' => $this->orderConfirmationTemplateId()],
            $this->context,
            [
                'eventName' => 'checkout.order.placed',
                'order' => $this->loadOrder($orderId),
            ],
        );

        $this->subscriber()->onMailBeforeValidate($event);

        $data = $event->getData();

        self::assertArrayNotHasKey(MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS, $data, 'Seit 1.1.0 bekommt der Kunde keine Anhänge mehr.');
    }

    /**
     * Greift die Freigabeliste nicht, hängt jede Mail zu einem Statuswechsel der Bestellung die
     * Anhänge erneut an und flutet das Postfach des Supports.
     */
    public function testAttachmentsAreNotAddedToShippingMail(): void
    {
        $orderId = $this->createTestOrder();
        $mediaId = $this->createStoredMedia();

        $this->manager()->attach(
            orderId: $orderId,
            mediaId: $mediaId,
            originalFileName: 'kundenzeichnung.pdf',
            mimeType: 'application/pdf',
            fileSize: \strlen(self::ATTACHMENT_CONTENT),
            context: $this->context,
        );

        $event = new MailBeforeValidateEvent(
            ['templateId' => $this->templateIdByType('order_delivery.state.shipped')],
            $this->context,
            [
                'eventName' => 'state_enter.order_delivery.state.shipped',
                'order' => $this->loadOrder($orderId),
            ],
        );

        $this->subscriber()->onMailBeforeValidate($event);

        self::assertArrayNotHasKey(
            MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS,
            $event->getData(),
        );
    }

    /**
     * Der Subscriber-Test zeigt nur, dass `binAttachments` im Ereignis landet. Dieser Test geht
     * durch `MailService::send()`, den echten Dispatcher (und damit die Registrierung des
     * Subscribers im Container), die Datenprüfung und die `MailFactory` bis zur fertigen Mail.
     */
    public function testMailServiceBuildsTheShopMailWithTheCustomerAttachment(): void
    {
        $orderId = $this->createTestOrder();
        $mediaId = $this->createStoredMedia();

        $this->manager()->attach(
            orderId: $orderId,
            mediaId: $mediaId,
            originalFileName: 'kundenzeichnung.pdf',
            mimeType: 'application/pdf',
            fileSize: \strlen(self::ATTACHMENT_CONTENT),
            context: $this->context,
        );

        $email = static::service(MailService::class)->send(
            [
                'recipients' => ['support@example.com' => 'Support'],
                'senderName' => 'Testshop',
                'senderEmail' => 'shop@example.com',
                'salesChannelId' => $this->getValidSalesChannelId(),
                'subject' => 'Ihre Bestellung',
                'contentHtml' => '<p>Danke für Ihre Bestellung.</p>',
                'contentPlain' => 'Danke für Ihre Bestellung.',
                'templateId' => $this->templateIdByType(ShopAttachmentMail::TEMPLATE_TYPE),
            ],
            $this->context,
            [
                'order' => $this->loadOrder($orderId),
                ShopAttachmentMail::DATA_KEY => ['attach' => true],
            ],
        );

        self::assertInstanceOf(Email::class, $email, 'MailService muss eine Mail erzeugen');

        $attachments = $email->getAttachments();
        self::assertCount(1, $attachments, 'Die Kundendatei muss als Mail-Anhang vorliegen');

        $attachment = $attachments[0];
        self::assertSame('kundenzeichnung.pdf', $attachment->getFilename());
        self::assertSame('application/pdf', $attachment->getMediaType() . '/' . $attachment->getMediaSubtype());
        self::assertSame(self::ATTACHMENT_CONTENT, $attachment->getBody());
    }

    /**
     * Die ganze Freigabeliste beruht auf der Annahme, dass `data['templateId']` zum Typ
     * `order_confirmation_mail` auflöst; geprüft wird sie gegen `mail_template` aus der Datenbank.
     */
    public function testResolverMapsTemplateIdToTechnicalName(): void
    {
        $resolver = static::service(MailTemplateTypeResolverInterface::class);

        self::assertSame(
            'order_confirmation_mail',
            $resolver->resolveTechnicalName($this->orderConfirmationTemplateId(), $this->context),
        );
    }

    private function subscriber(): MailBeforeValidateSubscriber
    {
        return static::service(MailBeforeValidateSubscriber::class);
    }

    private function manager(): OrderAttachmentManagerInterface
    {
        return static::service(OrderAttachmentManagerInterface::class);
    }

    private function orderConfirmationTemplateId(): string
    {
        return $this->templateIdByType('order_confirmation_mail');
    }

    private function templateIdByType(string $technicalName): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', $technicalName));
        $criteria->setLimit(1);

        $id = static::repository('mail_template.repository')
            ->searchIds($criteria, $this->context)->firstId();

        self::assertIsString($id, \sprintf('Mail-Template-Type "%s" muss im Test-Shop existieren', $technicalName));

        return $id;
    }

    private function loadOrder(string $orderId): OrderEntity
    {
        $order = static::repository('order.repository')
            ->search(new Criteria([$orderId]), $this->context)->getEntities()->first();

        self::assertInstanceOf(OrderEntity::class, $order);

        return $order;
    }

    /**
     * Legt den Mediendatensatz an und schreibt die Datei in den konfigurierten Speicher. Ohne
     * Datei kann `MediaService::loadFile()` nichts lesen, und der Test prüfte nur die halbe Kette.
     */
    private function createStoredMedia(): string
    {
        $mediaId = Uuid::randomHex();
        static::repository('media.repository')->create([[
            'id' => $mediaId,
            'private' => true,
        ]], $this->context);

        static::service(MediaService::class)->saveFile(
            self::ATTACHMENT_CONTENT,
            'pdf',
            'application/pdf',
            'rc-itest-' . $mediaId,
            $this->context,
            null,
            $mediaId,
            true,
        );

        return $mediaId;
    }
}
