<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Integration\Storefront\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\Mail\MailTemplateTypeResolverInterface;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentManagerInterface;
use Ruhrcoder\RcOrderAttachment\Storefront\Subscriber\MailBeforeValidateSubscriber;
use Ruhrcoder\RcOrderAttachment\Tests\Integration\ContainerAccessTrait;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Mail\Service\MailService;
use Shopware\Core\Content\MailTemplate\Service\Event\MailBeforeValidateEvent;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Mime\Email;

/**
 * End-to-End-Nachweis für die Kernanforderung: die vom Kunden hochgeladene
 * Datei muss an der Bestell-Bestätigungs-Mail hängen, damit der Support sie
 * bekommt.
 *
 * Der Test bildet exakt nach, was `SendMailAction` in Shopware 6.7 an
 * `MailService::send()` übergibt:
 *   - `data`         → enthält NUR `templateId`, keinen `mailTemplate`-Key
 *   - `templateData` → `['eventName' => 'checkout.order.placed', 'order' => OrderEntity, ...]`
 *
 * Genau daran ist die alte Implementierung gescheitert: sie verglich den
 * Flow-Event-Namen `checkout.order.placed` gegen den Whitelist-Default
 * `order_confirmation_mail` — es ging nie ein Anhang raus. Läuft gegen echte
 * DAL, echten Media-Storage und das echte Mail-Template aus der DB.
 */
final class MailAttachmentIntegrationTest extends TestCase
{
    use ContainerAccessTrait;
    use IntegrationTestBehaviour;

    private const ATTACHMENT_CONTENT = '%PDF-1.4 rc-order-attachment integration probe';

    private Context $context;

    protected function setUp(): void
    {
        $this->context = Context::createDefaultContext();
    }

    /**
     * Was: Der Mail-Subscriber läuft mit den echten Event-Daten einer
     *      Bestellbestätigung.
     * Warum: REGRESSION für den 🔴-Befund — ohne Auflösung der `templateId`
     *        zum Template-Type-`technicalName` bleibt `binAttachments` leer
     *        und der Support bekommt die Kundendatei nie.
     * Erwartet: `binAttachments` enthält genau einen Eintrag mit Original-
     *           Dateiname, korrektem MIME-Typ und dem echten Datei-Inhalt.
     */
    public function testCustomerUploadIsAttachedToOrderConfirmationMail(): void
    {
        // Nicht-Vakuitäts-Prüfung: Eine leere Whitelist würde jede Mail durchlassen
        // und der Test wäre wertlos. Er muss gegen die scharfe Default-Whitelist laufen.
        $config = static::service(PluginConfigProvider::class)
            ->getForSalesChannel($this->getValidSalesChannelId());
        self::assertFalse($config->mailTemplateWhitelistEmpty(), 'Test muss gegen eine aktive Whitelist laufen');
        self::assertSame(['order_confirmation_mail'], $config->mailTemplateWhitelist);
        self::assertTrue($config->enabled && $config->attachToConfirmationMail);

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
            // Exakt das, was SendMailAction setzt: templateId, kein mailTemplate.
            ['templateId' => $this->orderConfirmationTemplateId()],
            $this->context,
            [
                'eventName' => 'checkout.order.placed',
                'order' => $this->loadOrder($orderId),
            ],
        );

        $this->subscriber()->onMailBeforeValidate($event);

        $data = $event->getData();

        self::assertArrayHasKey(
            MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS,
            $data,
            'Der Anhang muss an der Bestätigungs-Mail hängen — sonst bekommt der Support die Datei nicht.',
        );

        $binAttachments = $data[MailBeforeValidateSubscriber::DATA_KEY_BIN_ATTACHMENTS];
        self::assertCount(1, $binAttachments);
        self::assertSame('kundenzeichnung.pdf', $binAttachments[0]['fileName']);
        self::assertSame('application/pdf', $binAttachments[0]['mimeType']);
        self::assertSame(self::ATTACHMENT_CONTENT, $binAttachments[0]['content']);
    }

    /**
     * Was: Derselbe Anhang, aber die Mail ist eine Versand-Benachrichtigung.
     * Warum: Die Whitelist muss weiterhin greifen — sonst hängt jede
     *        Order-State-Change-Mail die Anhänge erneut an und flutet die
     *        Support-Mailbox.
     * Erwartet: Kein `binAttachments`-Eintrag.
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
     * Was: Die vollständige Mail-Kette — `MailService::send()` mit exakt den
     *      Daten, die `SendMailAction` übergibt.
     * Warum: Der Subscriber-Test beweist nur, dass `binAttachments` im Event
     *        landet. Dieser Test beweist, dass Shopware daraus tatsächlich einen
     *        Mail-Anhang baut: er läuft durch den echten Event-Dispatcher (prüft
     *        also auch die DI-Registrierung des Subscribers), durch die
     *        Daten-Validierung und durch die `MailFactory`.
     * Erwartet: Das erzeugte `Email`-Objekt trägt genau einen Anhang mit
     *           Dateiname, MIME-Typ und Inhalt der Kundendatei.
     */
    public function testMailServiceBuildsEmailWithCustomerAttachment(): void
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
                'templateId' => $this->orderConfirmationTemplateId(),
            ],
            $this->context,
            [
                'eventName' => 'checkout.order.placed',
                'order' => $this->loadOrder($orderId),
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
     * Was: Der Resolver gegen das echte `mail_template` aus der DB.
     * Warum: Beweist, dass `data['templateId']` tatsächlich zum
     *        `order_confirmation_mail`-Type auflöst — die Annahme, auf der
     *        die gesamte Whitelist beruht.
     * Erwartet: `order_confirmation_mail`.
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
            ->search(new Criteria([$orderId]), $this->context)->first();

        self::assertInstanceOf(OrderEntity::class, $order);

        return $order;
    }

    /**
     * Legt einen Media-Datensatz an UND schreibt die Datei in den konfigurierten
     * Storage — `MediaService::loadFile()` muss sie später lesen können, sonst
     * misst der Test nur die halbe Kette.
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

    private function createTestOrder(): string
    {
        $orderId = Uuid::randomHex();
        static::repository('order.repository')->create([[
            'id' => $orderId,
            'orderNumber' => 'RCOA-' . $orderId,
            'orderDateTime' => '2026-05-20 10:00:00',
            'currencyId' => Defaults::CURRENCY,
            'currencyFactor' => 1.0,
            'salesChannelId' => $this->getValidSalesChannelId(),
            'stateId' => $this->getOrderStateId('open'),
            'price' => [
                'netPrice' => 100,
                'totalPrice' => 119,
                'positionPrice' => 100,
                'rawTotal' => 119,
                'taxStatus' => 'gross',
                'calculatedTaxes' => [],
                'taxRules' => [],
            ],
            'shippingCosts' => [
                'unitPrice' => 0,
                'totalPrice' => 0,
                'quantity' => 1,
                'calculatedTaxes' => [],
                'taxRules' => [],
            ],
            'totalRounding' => ['decimals' => 2, 'interval' => 0.01, 'roundForNet' => true],
            'itemRounding' => ['decimals' => 2, 'interval' => 0.01, 'roundForNet' => true],
            'orderCustomer' => [
                'email' => 'integration-test@example.com',
                'firstName' => 'Test',
                'lastName' => 'Customer',
                'salutationId' => $this->getSalutationId(),
            ],
            'billingAddressId' => Uuid::randomHex(),
            'addresses' => [],
        ]], $this->context);

        return $orderId;
    }

    private function getValidSalesChannelId(): string
    {
        $id = static::repository('sales_channel.repository')
            ->searchIds(new Criteria(), $this->context)->firstId();
        self::assertIsString($id, 'Mindestens ein Sales-Channel muss existieren');

        return $id;
    }

    private function getOrderStateId(string $technicalName): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('technicalName', $technicalName));
        $criteria->setLimit(1);

        $id = static::repository('state_machine_state.repository')
            ->searchIds($criteria, $this->context)->firstId();
        self::assertIsString($id);

        return $id;
    }

    private function getSalutationId(): string
    {
        $criteria = new Criteria();
        $criteria->setLimit(1);

        $id = static::repository('salutation.repository')
            ->searchIds($criteria, $this->context)->firstId();
        self::assertIsString($id);

        return $id;
    }
}
