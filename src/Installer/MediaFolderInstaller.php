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
 * Legt einen dedizierten, privaten Media-Folder für die Customer-Uploads an.
 *
 * Bewusst kein DefaultFolder mit `entity`-Bindung — die Uploads gehören nicht
 * der `order`-Entity (für die ist der Folder gedacht), sondern stehen logisch
 * dazwischen.
 *
 * **Wiedererkennung über die ID, nicht über den Namen.** Der Ordner wurde früher
 * ausschließlich über seinen Namen gesucht. Benennt ihn jemand im Medien-Manager um --
 * dort ist er ein ganz normaler Ordner --, fand ihn weder der Orphan-Cleanup noch die
 * Deinstallation: Der Cleanup räumte nichts mehr ab, obwohl die Aufbewahrungsfrist etwas
 * anderes verspricht, und beim Deinstallieren blieben Ordner samt privater Kundendateien
 * zurück. Die ID wird deshalb bei der Anlage vermerkt und zuerst gelesen; die Namenssuche
 * bleibt als Rückfall für Bestandsinstallationen, die den Vermerk noch nicht haben.
 */
final class MediaFolderInstaller
{
    public const FOLDER_NAME = 'RcOrderAttachment Customer Uploads';

    /**
     * Ablageort der Ordner-ID. Kein Admin-Feld: Der Wert wird ausschließlich vom Plugin
     * geschrieben und gelesen (deshalb `internal`), er gehört nicht in die Konfigurations-Maske.
     */
    public const FOLDER_ID_CONFIG_KEY = 'RcOrderAttachment.internal.mediaFolderId';

    /**
     * @param EntityRepository<MediaFolderCollection> $mediaFolderRepository
     * @param EntityRepository<MediaFolderConfigurationCollection> $mediaFolderConfigurationRepository
     * @param Connection|null $connection Der Vermerk wird direkt in `system_config` geschrieben und
     *        gelesen. Nicht über den SystemConfigService: Dessen Schreib-Methoden sind in 6.7
     *        als veraltet markiert (kommender Parameter `silent`), und ein Wert, den nur das
     *        Plugin kennt, braucht weder Cache-Invalidierung noch Konfigurations-Event. Im
     *        Lifecycle steht die Verbindung nicht immer bereit -- fehlt sie, greift die
     *        Namenssuche wie bisher.
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
     * Findet die Namenssuche einen Ordner, wird der Vermerk nachgetragen — eine
     * Bestandsinstallation heilt sich damit beim ersten Aufruf selbst.
     */
    public function findFolderId(Context $context): ?string
    {
        $vermerkt = $this->rememberedFolderId();
        if ($vermerkt !== null && $this->folderExists($vermerkt, $context)) {
            return $vermerkt;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::FOLDER_NAME));
        $criteria->setLimit(1);

        $gefunden = $this->mediaFolderRepository->searchIds($criteria, $context)->firstId();
        if ($gefunden !== null) {
            $this->rememberFolderId($gefunden);
        }

        return $gefunden;
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

        $wert = $this->connection->fetchOne(
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(configuration_value, "$._value"))
             FROM `system_config` WHERE configuration_key = :key LIMIT 1',
            ['key' => self::FOLDER_ID_CONFIG_KEY],
        );

        return \is_string($wert) && Uuid::isValid($wert) ? $wert : null;
    }

    private function rememberFolderId(string $folderId): void
    {
        $this->connection?->executeStatement(
            'INSERT INTO `system_config` (id, configuration_key, configuration_value, sales_channel_id, created_at)
             VALUES (:id, :key, :value, NULL, NOW(3))
             ON DUPLICATE KEY UPDATE configuration_value = :value, updated_at = NOW(3)',
            [
                'id' => Uuid::randomBytes(),
                'key' => self::FOLDER_ID_CONFIG_KEY,
                'value' => json_encode(['_value' => $folderId], \JSON_THROW_ON_ERROR),
            ],
        );
    }
}
