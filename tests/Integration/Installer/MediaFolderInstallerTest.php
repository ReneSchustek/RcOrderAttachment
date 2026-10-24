<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Integration\Installer;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcOrderAttachment\Installer\MediaFolderInstaller;
use Ruhrcoder\RcOrderAttachment\Tests\Integration\IntegrationTestCase;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Der Vermerk der Ordnerkennung in `system_config`. Er gilt shopweit, steht also mit
 * `sales_channel_id` NULL, und dort greift der eindeutige Schlüssel nicht.
 */
final class MediaFolderInstallerTest extends IntegrationTestCase
{
    private Connection $connection;

    private MediaFolderInstaller $installer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = static::connection();
        $this->installer = static::service(MediaFolderInstaller::class);
        $this->installer->ensureFolder($this->context);
    }

    /**
     * Zeigt der Vermerk auf einen gelöschten Ordner, findet die Namenssuche den richtigen und trägt ihn
     * nach. Danach steht genau ein Vermerk da, und er nennt den gefundenen Ordner.
     */
    public function testAStaleNoteIsReplacedNotDuplicated(): void
    {
        $this->remember(Uuid::randomHex());

        $first = $this->installer->findFolderId($this->context);
        $second = $this->installer->findFolderId($this->context);

        self::assertNotNull($first);
        self::assertSame($first, $second);
        self::assertSame([$first], $this->notes());
    }

    private function remember(string $folderId): void
    {
        $this->connection->executeStatement(
            'DELETE FROM `system_config` WHERE configuration_key = :key',
            ['key' => MediaFolderInstaller::FOLDER_ID_CONFIG_KEY],
        );
        $this->connection->insert('system_config', [
            'id' => Uuid::randomBytes(),
            'configuration_key' => MediaFolderInstaller::FOLDER_ID_CONFIG_KEY,
            'configuration_value' => json_encode(['_value' => $folderId], \JSON_THROW_ON_ERROR),
            'sales_channel_id' => null,
            'created_at' => '2026-01-01 00:00:00.000',
        ]);
    }

    /**
     * @return list<string>
     */
    private function notes(): array
    {
        /** @var list<string> $values */
        $values = $this->connection->fetchFirstColumn(
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(configuration_value, "$._value")) FROM `system_config` WHERE configuration_key = :key',
            ['key' => MediaFolderInstaller::FOLDER_ID_CONFIG_KEY],
        );

        return $values;
    }
}
