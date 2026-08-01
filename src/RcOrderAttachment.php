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
 * Plugin-Bootstrapper für RcOrderAttachment.
 *
 * Lifecycle-Notiz: Während `install()`/`update()` sind plugin-eigene Services
 * NICHT im DI-Container — Installer werden hier direkt instanziiert und nur
 * Core-Services aus dem Container geholt.
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

        // Idempotent — sicher bei jedem Update, fügt das Set nur hinzu falls es fehlt.
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

        // Die Verbindung wird über die Klasse geholt, nicht über den Symfony-Alias
        // `database_connection`: Der Alias ist privat und `get()` wirft darauf eine
        // ServiceNotFoundException. Das brach `uninstall()` ab, bevor irgendetwas aufgeräumt
        // war — die privaten Customer-Uploads blieben liegen, also genau das, was diese
        // Routine verhindern soll.
        $connection = $this->optionalService($container, Connection::class);
        if (!$connection instanceof Connection) {
            $this->logUninstallFailure($container, 'connection', 0, null);

            return;
        }

        $mediaIds = $connection->fetchFirstColumn(
            'SELECT LOWER(HEX(media_id)) FROM `rc_order_attachment`'
        );

        $connection->executeStatement('DROP TABLE IF EXISTS `rc_order_attachment`');

        $this->customFieldInstaller($container)?->uninstall(Context::createCLIContext());
        $this->deleteMediaRecords($container, $mediaIds);
        $this->deletePluginMediaFolder($container, $connection);
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
     * Holt einen Core-Service, ohne den Plugin-Lifecycle zu sprengen.
     *
     * `ContainerInterface::get()` wirft bei unbekannter **oder nicht-öffentlicher** ID. Eine
     * ungeprüfte Abfrage reisst damit `install()`/`uninstall()` mitten im Ablauf ab und
     * hinterlässt einen halben Zustand. Wer eine ID falsch rät, soll das im Log finden — nicht
     * an einem abgebrochenen Kommando.
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
     * `logger` ist in Shopware 6.7 **nicht öffentlich** — die Protokollierung der
     * Aufräum-Fehler lief deshalb bisher ins Leere, obwohl sie ausdrücklich gegen stille
     * DSGVO-Rückstände eingebaut worden war. Gemessen auf einer 6.7.12-Instanz:
     * `logger` und `Psr\Log\LoggerInterface` privat, `monolog.logger.business_events`
     * öffentlich. Die Liste bleibt trotzdem gestaffelt, weil die Sichtbarkeit eine
     * Container-Entscheidung der jeweiligen Installation ist und sich ändern kann.
     *
     * @var list<string>
     */
    private const LOGGER_SERVICE_IDS = [
        'logger',
        LoggerInterface::class,
        'monolog.logger.business_events',
    ];

    /**
     * Der Logger wird ohne den Umweg über {@see optionalService()} geholt — sonst entstünde
     * beim Fehlschlag eine Endlosschleife (Protokollieren, dass das Protokollieren fehlt).
     * Findet sich keiner, läuft der Lifecycle still weiter: ein abgebrochener Uninstall wäre
     * schlimmer als ein fehlender Log-Eintrag.
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
            // Uninstall bleibt tolerant (eine harte Exception würde einen halben
            // Zustand hinterlassen), aber der Fehler MUSS diagnostizierbar sein:
            // Der plugin-eigene Orphan-Cleanup-Cron ist nach der Deinstallation
            // deregistriert — es gibt keine zweite Chance. Zurückbleibende private
            // Customer-Uploads (PII) müssen sonst unbemerkt liegen (DSGVO).
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

    private function deletePluginMediaFolder(ContainerInterface $container, Connection $connection): void
    {
        // Erst der Vermerk aus der Konfiguration, dann der Name. Wurde der Ordner im
        // Medien-Manager umbenannt, fand ihn die reine Namenssuche nicht mehr -- dann blieben
        // Ordner und private Kundendateien beim Deinstallieren zurück, obwohl das Plugin
        // Löschung zusagt.
        $folderId = $connection->fetchOne(
            'SELECT JSON_UNQUOTE(JSON_EXTRACT(configuration_value, "$._value"))
             FROM `system_config` WHERE configuration_key = :key LIMIT 1',
            ['key' => MediaFolderInstaller::FOLDER_ID_CONFIG_KEY],
        );

        if (!\is_string($folderId) || !Uuid::isValid($folderId)) {
            $folderId = $connection->fetchOne(
                'SELECT LOWER(HEX(id)) FROM `media_folder` WHERE name = :name LIMIT 1',
                ['name' => MediaFolderInstaller::FOLDER_NAME],
            );
        }

        if (!\is_string($folderId) || !Uuid::isValid($folderId)) {
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
