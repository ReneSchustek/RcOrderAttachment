<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Installer;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
use Shopware\Core\Content\Media\Aggregate\MediaFolderConfiguration\MediaFolderConfigurationCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Legt einen eigenen, privaten Medienordner für die Uploads der Kunden an.
 *
 * Ein Standardordner mit Bindung an eine Entität passt nicht: Die Uploads gehören weder ganz zur
 * Bestellung noch zu einer anderen Entität, sondern entstehen vor der Bestellung.
 *
 * Wiedererkannt wird der Ordner über seine ID. In der Medienverwaltung ist er ein gewöhnlicher
 * Ordner, den jemand umbenennen kann; eine reine Namenssuche fände ihn dann weder in der
 * Aufräumaufgabe noch bei der Deinstallation, und private Kundendateien blieben über die
 * Aufbewahrungsfrist hinaus liegen. Die ID wird deshalb bei der Anlage vermerkt und zuerst
 * gelesen. Die Namenssuche bleibt als Rückfall für Installationen ohne Vermerk.
 */
final class MediaFolderInstaller
{
    public const FOLDER_NAME = 'RcOrderAttachment Customer Uploads';

    /**
     * Ablageort der Ordner-ID. Den Wert schreibt und liest nur das Plugin, daher `internal` im
     * Schlüssel und kein Feld in der Konfigurationsmaske.
     */
    public const FOLDER_ID_CONFIG_KEY = 'RcOrderAttachment.internal.mediaFolderId';

    /**
     * @param EntityRepository<MediaFolderCollection> $mediaFolderRepository
     * @param EntityRepository<MediaFolderConfigurationCollection> $mediaFolderConfigurationRepository
     * @param Connection|null $connection Der Vermerk wird direkt in `system_config` geschrieben und
     *        gelesen, nicht über den SystemConfigService: Dessen Schreibmethoden sind in 6.7 als
     *        veraltet markiert (kommender Parameter `silent`), und ein Wert, den nur das Plugin
     *        kennt, braucht weder Cache-Invalidierung noch Konfigurationsereignis. Bei der
     *        Installation steht die Verbindung nicht immer bereit; fehlt sie, bleibt nur die
     *        Namenssuche.
     */
    public function __construct(
        private readonly EntityRepository $mediaFolderRepository,
        private readonly EntityRepository $mediaFolderConfigurationRepository,
        private readonly ?Connection $connection = null,
    ) {
    }

    public function ensureFolder(Context $context): string
    {
        $existingId = $this->findFolderId($context);
        if ($existingId !== null) {
            return $existingId;
        }

        $folderId = Uuid::randomHex();
        $configurationId = Uuid::randomHex();

        $this->mediaFolderConfigurationRepository->create([[
            'id' => $configurationId,
            'createThumbnails' => false,
            'keepAspectRatio' => true,
            // Wirkungslos, solange `createThumbnails` aus ist. 80 ist ein üblicher JPEG-Wert für
            // den Fall, dass jemand Vorschaubilder einschaltet.
            'thumbnailQuality' => 80,
            'private' => true,
        ]], $context);

        $this->mediaFolderRepository->create([[
            'id' => $folderId,
            'name' => self::FOLDER_NAME,
            'useParentConfiguration' => false,
            'configurationId' => $configurationId,
        ]], $context);

        $this->rememberFolderId($folderId);

        return $folderId;
    }

    /**
     * Liefert die ID des Plugin-Ordners: erst über den Vermerk, dann über den Namen.
     *
     * Findet erst die Namenssuche den Ordner, trägt die Methode den Vermerk nach, sodass eine
     * Installation ohne Vermerk ihn beim ersten Aufruf bekommt.
     */
    public function findFolderId(Context $context): ?string
    {
        $remembered = $this->rememberedFolderId();
        if ($remembered !== null && $this->folderExists($remembered, $context)) {
            return $remembered;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::FOLDER_NAME));
        $criteria->setLimit(1);

        $found = $this->mediaFolderRepository->searchIds($criteria, $context)->firstId();
        if ($found !== null) {
            $this->rememberFolderId($found);
        }

        return $found;
    }

    private function folderExists(string $folderId, Context $context): bool
    {
        if (!Uuid::isValid($folderId)) {
            return false;
        }

        return $this->mediaFolderRepository->searchIds(new Criteria([$folderId]), $context)->firstId() !== null;
    }

    private function rememberedFolderId(): ?string
    {
        if ($this->connection === null) {
            return null;
        }

        $value = $this->connection->fetchOne(
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(configuration_value, "$._value"))
             FROM `system_config` WHERE configuration_key = :key LIMIT 1',
            ['key' => self::FOLDER_ID_CONFIG_KEY],
        );

        return \is_string($value) && Uuid::isValid($value) ? $value : null;
    }

    private function rememberFolderId(string $folderId): void
    {
        // Die Verbindung kann fehlen; dann bleibt der Vermerk aus, und die Namenssuche trägt.
        if ($this->connection === null) {
            return;
        }

        // Löschen und neu schreiben statt `ON DUPLICATE KEY UPDATE`: Der eindeutige Schlüssel umfasst
        // `sales_channel_id`, und NULL gleicht dort keinem anderen NULL. Jeder Aufruf legte so eine
        // weitere Zeile an, und das Lesen mit `LIMIT 1` konnte die alte Kennung treffen.
        $this->connection->transactional(function (Connection $connection) use ($folderId): void {
            $connection->executeStatement(
                'DELETE FROM `system_config` WHERE configuration_key = :key AND sales_channel_id IS NULL',
                ['key' => self::FOLDER_ID_CONFIG_KEY],
            );
            $connection->executeStatement(
                'INSERT INTO `system_config` (id, configuration_key, configuration_value, sales_channel_id, created_at)
                 VALUES (:id, :key, :value, NULL, NOW(3))',
                [
                    'id' => Uuid::randomBytes(),
                    'key' => self::FOLDER_ID_CONFIG_KEY,
                    'value' => json_encode(['_value' => $folderId], \JSON_THROW_ON_ERROR),
                ],
            );
        });
    }
}
