<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\ScheduledTask;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Installer\MediaFolderInstaller;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * @internal
 */
#[AsMessageHandler(handles: OrphanedAttachmentCleanupTask::class)]
final class OrphanedAttachmentCleanupTaskHandler extends ScheduledTaskHandler
{
    private const BATCH_SIZE = 500;

    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly Connection $connection,
        private readonly EntityRepository $mediaRepository,
        private readonly MediaFolderInstaller $mediaFolderInstaller,
        private readonly PluginConfigProvider $configProvider,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $context = Context::createCLIContext();
        $config = $this->configProvider->getForSalesChannel();
        $folderId = $this->mediaFolderInstaller->findFolderId($context);

        if ($folderId === null) {
            // Warnung, nicht debug: Der Medienordner wird über seinen NAMEN gesucht. Benennt
            // ihn jemand im Medien-Manager um, findet der Cleanup ihn nicht mehr -- und
            // hochgeladene Kundendateien blieben unbemerkt liegen, obwohl die Aufbewahrungs-
            // frist etwas anderes verspricht. Auf debug war dieser Fall unsichtbar.
            $this->logger->warning('rc_order_attachment.cleanup.orphans.no_folder', [
                'hinweis' => 'Medienordner nicht gefunden -- wurde er im Medien-Manager umbenannt? '
                    . 'Ohne ihn räumt der Cleanup keine verwaisten Uploads ab.',
            ]);

            return;
        }

        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(\sprintf('-%d hours', $config->orphanRetentionHours))
            ->format('Y-m-d H:i:s.v');

        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
            SELECT LOWER(HEX(m.id)) AS id
            FROM `media` m
            LEFT JOIN `rc_order_attachment` a ON a.media_id = m.id
            WHERE m.media_folder_id = :folderId
              AND m.created_at < :cutoff
              AND a.id IS NULL
            LIMIT :limit
            SQL,
            [
                'folderId' => Uuid::fromHexToBytes($folderId),
                'cutoff' => $cutoff,
                'limit' => self::BATCH_SIZE,
            ],
            [
                'limit' => ParameterType::INTEGER,
            ],
        );

        $ids = array_values(array_filter($rows, Uuid::isValid(...)));
        if ($ids === []) {
            $this->logger->debug('rc_order_attachment.cleanup.orphans.empty');

            return;
        }

        $payload = array_map(static fn (string $id): array => ['id' => $id], $ids);

        try {
            $this->mediaRepository->delete($payload, $context);
            $this->logger->info('rc_order_attachment.cleanup.orphans.deleted', [
                'count' => \count($payload),
                'olderThanHours' => $config->orphanRetentionHours,
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('rc_order_attachment.cleanup.orphans.failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
