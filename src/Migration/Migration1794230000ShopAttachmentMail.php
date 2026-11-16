<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Vorlage der Mail, mit der der Shop die Kundendateien einer Bestellung bekommt.
 */
class Migration1794230000ShopAttachmentMail extends MigrationStep
{
    private const TYPE = 'rc_order_attachment.shop_notification';

    public function getCreationTimestamp(): int
    {
        return 1794230000;
    }

    public function update(Connection $connection): void
    {
        if ($connection->fetchOne('SELECT 1 FROM `mail_template_type` WHERE `technical_name` = :name', ['name' => self::TYPE]) !== false) {
            return;
        }

        $typeId = Uuid::randomBytes();
        $templateId = Uuid::randomBytes();
        $now = gmdate('Y-m-d H:i:s.v');
        $connection->insert('mail_template_type', [
            'id' => $typeId,
            'technical_name' => self::TYPE,
            'available_entities' => json_encode(['order' => 'order', 'salesChannel' => 'sales_channel'], \JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);
        $connection->insert('mail_template', ['id' => $templateId, 'mail_template_type_id' => $typeId, 'system_default' => 1, 'created_at' => $now]);

        $languages = $connection->fetchAllAssociative('SELECT `language`.`id`, `locale`.`code` FROM `language` JOIN `locale` ON `locale`.`id` = `language`.`locale_id`');
        foreach ($languages as $language) {
            $german = str_starts_with((string) $language['code'], 'de');
            $connection->insert('mail_template_type_translation', [
                'mail_template_type_id' => $typeId,
                'language_id' => $language['id'],
                'name' => $german ? 'Bestell-Anhänge an den Shop' : 'Order attachments to the shop',
                'created_at' => $now,
            ]);
            $connection->insert('mail_template_translation', [
                'mail_template_id' => $templateId,
                'language_id' => $language['id'],
                'sender_name' => '{{ salesChannel.name }}',
                'subject' => ($german ? 'Anhänge zur Bestellung ' : 'Attachments for order ') . '{{ order.orderNumber }}',
                'description' => $german ? 'Kundendateien einer Bestellung, nur an den Shop' : 'Customer files of an order, to the shop only',
                'content_html' => self::html($german),
                'content_plain' => self::plain($german),
                'created_at' => $now,
            ]);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }

    private static function html(bool $german): string
    {
        [$intro, $attached, $tooBig, $link] = $german
            ? ['Zur Bestellung {{ order.orderNumber }} von {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }} ({{ order.orderCustomer.email }}) wurden Dateien hochgeladen:', 'Die Dateien hängen an dieser Mail.', 'Die Dateien sind zusammen {{ rcOrderAttachment.totalMb }} MB groß und deshalb nicht angehängt.', 'Bestellung in der Verwaltung öffnen']
            : ['Files were uploaded for order {{ order.orderNumber }} by {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }} ({{ order.orderCustomer.email }}):', 'The files are attached to this e-mail.', 'The files total {{ rcOrderAttachment.totalMb }} MB and are therefore not attached.', 'Open the order in the administration'];

        return '<p>' . $intro . '</p>' . "\n"
            . '<ul>{% for file in rcOrderAttachment.files %}<li>{{ file.name }} ({{ file.size }} MB)</li>{% endfor %}</ul>' . "\n"
            . '<p>{% if rcOrderAttachment.attach %}' . $attached . '{% else %}' . $tooBig . '{% endif %}</p>' . "\n"
            . '<p><a href="{{ rcOrderAttachment.adminUrl }}">' . $link . '</a></p>';
    }

    private static function plain(bool $german): string
    {
        [$intro, $attached, $tooBig, $link] = $german
            ? ['Zur Bestellung {{ order.orderNumber }} von {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }} ({{ order.orderCustomer.email }}) wurden Dateien hochgeladen:', 'Die Dateien hängen an dieser Mail.', 'Die Dateien sind zusammen {{ rcOrderAttachment.totalMb }} MB groß und deshalb nicht angehängt.', 'Bestellung in der Verwaltung: ']
            : ['Files were uploaded for order {{ order.orderNumber }} by {{ order.orderCustomer.firstName }} {{ order.orderCustomer.lastName }} ({{ order.orderCustomer.email }}):', 'The files are attached to this e-mail.', 'The files total {{ rcOrderAttachment.totalMb }} MB and are therefore not attached.', 'Order in the administration: '];

        return $intro . "\n\n" . "{% for file in rcOrderAttachment.files %}- {{ file.name }} ({{ file.size }} MB)\n{% endfor %}" . "\n"
            . '{% if rcOrderAttachment.attach %}' . $attached . '{% else %}' . $tooBig . '{% endif %}' . "\n\n" . $link . '{{ rcOrderAttachment.adminUrl }}';
    }
}
