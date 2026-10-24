<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Installer;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\CustomFieldTypes;

/**
 * Legt das Zusatzfeld-Set für den Anhangstatus der Bestellbestätigung an, mit dem Feld
 * `rc_order_attachment_mail_status` an der Entität `order`.
 *
 * Kunden dürfen das Feld nicht beschreiben (`allowCustomerWrite` ist `false`). Es dient nur dem
 * Betrieb, um Bestellungen zu finden, deren Bestätigung ohne alle Anhänge hinausging.
 */
final class CustomFieldInstaller
{
    public const SET_NAME = 'rc_order_attachment';
    public const FIELD_MAIL_STATUS = 'rc_order_attachment_mail_status';

    public const STATUS_ATTACHED = 'attached';
    public const STATUS_PARTIAL_FAILURE = 'partial_failure';
    public const STATUS_FAILED = 'failed';

    /**
     * Feste IDs, damit `upsert` ohne vorherige Suche beliebig oft laufen kann. Set und Feld
     * brauchen beide eine: Ein verschachteltes Feld ohne `id` bekäme bei jedem Aufruf eine neue
     * Zufalls-ID. Weil `update()` bei jedem Plugin-Update `install()` aufruft, liefe der Writer
     * beim zweiten Mal in den eindeutigen Index auf `custom_field.name`, und `plugin:update`
     * bräche ab.
     */
    private const SET_ID = '0bc4f1a4b6e34ca7a2a9b4a4ad44cf01';
    private const FIELD_ID = '0bc4f1a4b6e34ca7a2a9b4a4ad44cf02';

    /**
     * Auch die Zuordnung des Sets zur Entität braucht eine feste ID. Ohne sie vergäbe der Upsert
     * bei jedem Aufruf eine neue ID und liefe gegen den eindeutigen Index
     * `uniq.custom_field_set_relation.entity_name`, sodass `plugin:update` abbräche.
     */
    private const RELATION_ID = '0bc4f1a4b6e34ca7a2a9b4a4ad44cf03';

    /**
     * @param EntityRepository<CustomFieldSetCollection> $customFieldSetRepository
     * @param EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>> $customFieldRepository
     * @param EntityRepository<\Shopware\Core\Framework\DataAbstractionLayer\EntityCollection<\Shopware\Core\Framework\DataAbstractionLayer\Entity>> $customFieldSetRelationRepository
     */
    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
        private readonly EntityRepository $customFieldRepository,
        private readonly EntityRepository $customFieldSetRelationRepository,
    ) {
    }

    public function install(Context $context): void
    {
        // Ältere Installationen tragen Feld und Zuordnung unter Zufalls-IDs. Ohne dieses Aufräumen
        // liefe der Upsert mit festen IDs gegen die eindeutigen Indizes. Die Werte an den
        // Bestellungen bleiben erhalten, weil sie im JSON unter dem Feldnamen liegen, nicht unter
        // der Feld-ID.
        $this->removeLegacyField($context);
        $this->removeLegacyRelation($context);

        $this->customFieldSetRepository->upsert([[
            'id' => self::SET_ID,
            'name' => self::SET_NAME,
            'global' => false,
            'config' => [
                'label' => [
                    'de-DE' => 'Bestellungs-Anhänge',
                    'en-GB' => 'Order Attachments',
                ],
                'translated' => true,
            ],
            'customFields' => [[
                'id' => self::FIELD_ID,
                'name' => self::FIELD_MAIL_STATUS,
                'type' => CustomFieldTypes::SELECT,
                'allowCustomerWrite' => false,
                'config' => [
                    'componentName' => 'sw-single-select',
                    'customFieldType' => CustomFieldTypes::SELECT,
                    'label' => [
                        'de-DE' => 'Mail-Anhang-Status',
                        'en-GB' => 'Mail attachment status',
                    ],
                    'helpText' => [
                        'de-DE' => 'Markiert, ob die Anhänge erfolgreich an die Bestätigungs-Mail gehängt wurden.',
                        'en-GB' => 'Marks whether the attachments were successfully appended to the confirmation mail.',
                    ],
                    'options' => [
                        ['value' => self::STATUS_ATTACHED, 'label' => ['de-DE' => 'Angehängt', 'en-GB' => 'Attached']],
                        ['value' => self::STATUS_PARTIAL_FAILURE, 'label' => ['de-DE' => 'Teilweise fehlgeschlagen', 'en-GB' => 'Partial failure']],
                        ['value' => self::STATUS_FAILED, 'label' => ['de-DE' => 'Fehlgeschlagen', 'en-GB' => 'Failed']],
                    ],
                    'customFieldPosition' => 1,
                ],
            ]],
            'relations' => [[
                'id' => self::RELATION_ID,
                'entityName' => 'order',
            ]],
        ]], $context);
    }

    public function uninstall(Context $context): void
    {
        $this->customFieldSetRepository->delete([['id' => self::SET_ID]], $context);
    }

    /**
     * Löscht Statusfelder gleichen Namens, die unter einer anderen ID als {@see FIELD_ID} liegen.
     * Erst danach kann der Upsert mit fester ID laufen, ohne an `custom_field.name` zu scheitern.
     */
    private function removeLegacyField(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::FIELD_MAIL_STATUS));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('id', self::FIELD_ID),
        ]));

        $ids = $this->customFieldRepository->searchIds($criteria, $context)->getIds();
        if ($ids === []) {
            return;
        }

        $payload = array_map(static fn (string $id): array => ['id' => $id], $ids);
        $this->customFieldRepository->delete($payload, $context);
    }

    /**
     * Gegenstück zu {@see removeLegacyField()} für die Zuordnung des Sets zur Bestellung, die in
     * älteren Installationen unter einer Zufalls-ID liegt.
     */
    private function removeLegacyRelation(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('entityName', 'order'));
        $criteria->addFilter(new EqualsFilter('customFieldSetId', self::SET_ID));
        $criteria->addFilter(new NotFilter(NotFilter::CONNECTION_AND, [
            new EqualsFilter('id', self::RELATION_ID),
        ]));

        $ids = $this->customFieldSetRelationRepository->searchIds($criteria, $context)->getIds();
        if ($ids === []) {
            return;
        }

        $payload = array_map(static fn (string $id): array => ['id' => $id], $ids);
        $this->customFieldSetRelationRepository->delete($payload, $context);
    }
}
