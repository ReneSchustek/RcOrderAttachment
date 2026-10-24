<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Installer\CustomFieldInstaller;
use Ruhrcoder\RcOrderAttachment\Installer\MediaFolderInstaller;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Throwable;

/**
 * Einstieg des Plugins: legt bei Installation und Update den Medienordner und das Zusatzfeld an
 * und räumt bei der Deinstallation ohne `keepUserData` Tabelle, Medien und Ordner ab.
 *
 * Während `install()` und `update()` stehen die eigenen Dienste des Plugins noch nicht im
 * Container. Die Installer entstehen deshalb von Hand, und aus dem Container kommen nur
 * Kerndienste, jeder über {@see optionalService()}, damit ein fehlender Dienst den Ablauf nicht
 * abbricht.
 */
final class RcOrderAttachment extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        $container = $this->container;
        if ($container === null) {
            return;
        }

        $cliContext = Context::createCLIContext();

        $folderRepo = $this->optionalService($container, 'media_folder.repository');
        $folderConfigRepo = $this->optionalService($container, 'media_folder_configuration.repository');
        if ($folderRepo instanceof EntityRepository && $folderConfigRepo instanceof EntityRepository) {
            $verbindung = $this->optionalService($container, Connection::class);
            $mediaFolderInstaller = new MediaFolderInstaller(
                $folderRepo,
                $folderConfigRepo,
                $verbindung instanceof Connection ? $verbindung : null,
            );
            $mediaFolderInstaller->ensureFolder($cliContext);
        }

        $this->customFieldInstaller($container)?->install($cliContext);
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $container = $this->container;
        if ($container === null) {
            return;
        }

        // Der Installer arbeitet mit festen IDs per Upsert und verträgt jeden erneuten Aufruf.
        $this->customFieldInstaller($container)?->install(Context::createCLIContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $container = $this->container;
        if ($container === null) {
            return;
        }

        // Die Verbindung kommt über den Klassennamen, nicht über den Alias `database_connection`.
        // Der Alias ist privat, `get()` wirft darauf eine ServiceNotFoundException, und
        // `uninstall()` bräche ab, bevor die privaten Uploads der Kunden gelöscht sind.
        $connection = $this->optionalService($container, Connection::class);
        if (!$connection instanceof Connection) {
            $this->logUninstallFailure($container, 'connection', 0, null);

            return;
        }

        // Die Medien-IDs müssen vor dem DROP gelesen sein, danach gibt es die Tabelle nicht mehr.
        $mediaIds = $connection->fetchFirstColumn(
            'SELECT LOWER(HEX(media_id)) FROM `rc_order_attachment`'
        );

        $connection->executeStatement('DROP TABLE IF EXISTS `rc_order_attachment`');

        $this->customFieldInstaller($container)?->uninstall(Context::createCLIContext());

        // Vorgemerkte und verwaiste Uploads haben keine Zeile in `rc_order_attachment`; sie liegen nur
        // im Ordner des Plugins. Fiele der Ordner allein, setzte Shopware ihren Ordner auf NULL, und die
        // privaten Dateien der Kunden blieben ohne Ordner liegen.
        $folderId = $this->pluginMediaFolderId($connection);
        $this->deleteMediaRecords($container, $this->mediaIdsToDelete($connection, $mediaIds, $folderId));
        $this->deletePluginMediaFolder($container, $folderId);
    }

    /**
     * Die Medien mit Bestellung und alle, die noch im Ordner des Plugins liegen.
     *
     * @param array<int, mixed> $linkedIds
     *
     * @return list<string>
     */
    private function mediaIdsToDelete(Connection $connection, array $linkedIds, ?string $folderId): array
    {
        $folderMediaIds = $folderId === null ? [] : $connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM `media` WHERE media_folder_id = :folderId',
            ['folderId' => Uuid::fromHexToBytes($folderId)],
        );

        return array_values(array_unique(array_map(
            static fn (mixed $id): string => strtolower((string) $id),
            array_merge($linkedIds, $folderMediaIds),
        )));
    }

    private function customFieldInstaller(ContainerInterface $container): ?CustomFieldInstaller
    {
        $setRepository = $this->optionalService($container, 'custom_field_set.repository');
        $fieldRepository = $this->optionalService($container, 'custom_field.repository');
        $relationRepository = $this->optionalService($container, 'custom_field_set_relation.repository');

        if (!$setRepository instanceof EntityRepository
            || !$fieldRepository instanceof EntityRepository
            || !$relationRepository instanceof EntityRepository
        ) {
            return null;
        }

        return new CustomFieldInstaller($setRepository, $fieldRepository, $relationRepository);
    }

    /**
     * Liefert einen Kerndienst oder `null`, wenn der Container ihn nicht herausgibt.
     *
     * `ContainerInterface::get()` wirft bei unbekannter oder nicht öffentlicher ID. Eine
     * ungeprüfte Abfrage risse `install()` oder `uninstall()` mitten im Ablauf ab und hinterließe
     * einen halben Zustand. Eine falsch geratene ID steht deshalb im Protokoll, statt das Kommando
     * abzubrechen.
     */
    private function optionalService(ContainerInterface $container, string $id): ?object
    {
        try {
            $service = $container->get($id);
        } catch (Throwable $exception) {
            $this->logger($container)?->error('rc_order_attachment.lifecycle.service_missing', [
                'serviceId' => $id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        return $service;
    }

    /**
     * Logger-Kandidaten in der Reihenfolge, in der sie versucht werden.
     *
     * In Shopware 6.7 sind `logger` und `Psr\Log\LoggerInterface` privat, öffentlich ist nur
     * `monolog.logger.business_events`. Mit `logger` allein liefen die Meldungen über
     * zurückgebliebene Kundendateien ins Leere. Die Liste bleibt gestaffelt, weil die
     * Sichtbarkeit eine Entscheidung des Containers der jeweiligen Installation ist und sich
     * ändern kann.
     *
     * @var list<string>
     */
    private const LOGGER_SERVICE_IDS = [
        'logger',
        LoggerInterface::class,
        'monolog.logger.business_events',
    ];

    /**
     * Holt den Logger ohne {@see optionalService()}, weil der bei einem Fehlschlag selbst wieder
     * protokollieren will und sich so endlos aufriefe. Findet sich keiner, läuft der Ablauf still
     * weiter: Eine abgebrochene Deinstallation wäre schlimmer als ein fehlender Protokolleintrag.
     */
    private function logger(ContainerInterface $container): ?LoggerInterface
    {
        foreach (self::LOGGER_SERVICE_IDS as $id) {
            try {
                $logger = $container->get($id);
            } catch (Throwable) {
                continue;
            }

            if ($logger instanceof LoggerInterface) {
                return $logger;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $mediaIds
     */
    private function deleteMediaRecords(ContainerInterface $container, array $mediaIds): void
    {
        $ids = array_values(array_filter(
            array_map(static fn ($id): string => (string) $id, $mediaIds),
            Uuid::isValid(...),
        ));
        if ($ids === []) {
            return;
        }

        $repository = $this->optionalService($container, 'media.repository');
        if (!$repository instanceof EntityRepository) {
            return;
        }

        $payload = array_map(static fn (string $id): array => ['id' => $id], $ids);

        try {
            $repository->delete($payload, Context::createCLIContext());
        } catch (Throwable $exception) {
            // Die Deinstallation läuft weiter, weil eine Ausnahme einen halben Zustand hinterließe.
            // Der Fehler muss aber im Protokoll stehen: Die eigene Aufräumaufgabe ist danach
            // abgemeldet, und zurückgebliebene private Dateien der Kunden blieben sonst
            // unbemerkt liegen.
            $this->logUninstallFailure($container, 'media_records', \count($ids), $exception);
        }
    }

    private function logUninstallFailure(ContainerInterface $container, string $stage, int $affected, ?Throwable $exception): void
    {
        $this->logger($container)?->error('rc_order_attachment.uninstall.cleanup_failed', [
            'stage' => $stage,
            'affected' => $affected,
            'exception' => $exception?->getMessage() ?? 'Service nicht verfügbar',
        ]);
    }

    /**
     * Erst der Vermerk aus der Konfiguration, dann der Name. Hat jemand den Ordner in der
     * Medienverwaltung umbenannt, findet ihn die Namenssuche allein nicht, und der Ordner bliebe
     * beim Deinstallieren zurück.
     */
    private function pluginMediaFolderId(Connection $connection): ?string
    {
        $folderId = $connection->fetchOne(
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(configuration_value, "$._value"))
             FROM `system_config` WHERE configuration_key = :key LIMIT 1',
            ['key' => MediaFolderInstaller::FOLDER_ID_CONFIG_KEY],
        );

        // Ein Vermerk auf einen schon gelöschten Ordner zählt nicht; dann trägt die Namenssuche.
        $exists = \is_string($folderId) && Uuid::isValid($folderId) && $connection->fetchOne(
            'SELECT 1 FROM `media_folder` WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($folderId)],
        ) !== false;

        if (!$exists) {
            $folderId = $connection->fetchOne(
                'SELECT LOWER(HEX(id)) FROM `media_folder` WHERE name = :name LIMIT 1',
                ['name' => MediaFolderInstaller::FOLDER_NAME],
            );
        }

        return \is_string($folderId) && Uuid::isValid($folderId) ? strtolower($folderId) : null;
    }

    private function deletePluginMediaFolder(ContainerInterface $container, ?string $folderId): void
    {
        if ($folderId === null) {
            return;
        }

        $repository = $this->optionalService($container, 'media_folder.repository');
        if (!$repository instanceof EntityRepository) {
            return;
        }

        try {
            $repository->delete(
                [['id' => $folderId]],
                Context::createCLIContext(),
            );
        } catch (Throwable $exception) {
            $this->logUninstallFailure($container, 'media_folder', 1, $exception);
        }
    }
}
