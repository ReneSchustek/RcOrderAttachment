<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\ScheduledTask;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Löscht abgelaufene Anhänge in zwei Schritten.
 *
 * Zuerst gehen die `rc_order_attachment`-Zeilen, die älter als die Frist sind. Erst danach löscht
 * der Handler die Medien, auf die keine Zeile mehr zeigt. So bleibt ein Medium erhalten, solange
 * eine jüngere Zeile einer anderen Bestellung noch darauf verweist.
 *
 * @internal
 */
#[AsMessageHandler(handles: ExpiredAttachmentCleanupTask::class)]
final class ExpiredAttachmentCleanupTaskHandler extends ScheduledTaskHandler
{
    // Begrenzt Speicher und Dauer eines Laufs. Bei täglichem Lauf löscht die Aufgabe höchstens
    // 500 Anhänge am Tag; was darüber liegt, folgt in den nächsten Läufen. Ein größerer Wert
    // verlängert die Löschanfrage an Medien und Dateisystem entsprechend.
    private const BATCH_SIZE = 500;

    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     * @param EntityRepository<OrderAttachmentCollection> $attachmentRepository
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly Connection $connection,
        private readonly EntityRepository $attachmentRepository,
        private readonly EntityRepository $mediaRepository,
        private readonly PluginConfigProvider $configProvider,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $config = $this->configProvider->getForSalesChannel();
        if (!$config->attachmentRetentionEnabled()) {
            $this->logger->debug('rc_order_attachment.cleanup.expired.disabled');

            return;
        }

        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify(\sprintf('-%d days', $config->attachmentRetentionDays))
            ->format('Y-m-d H:i:s.v');

        $context = Context::createCLIContext();

        $expiredRows = $this->fetchExpired($cutoff);
        if ($expiredRows === []) {
            $this->logger->debug('rc_order_attachment.cleanup.expired.empty');

            return;
        }

        $attachmentIds = array_keys($expiredRows);
        $candidateMediaIds = array_values(array_unique($expiredRows));

        $this->deleteAttachments($attachmentIds, $context);
        $this->deleteUnreferencedMedia($candidateMediaIds, $context);

        $this->logger->info('rc_order_attachment.cleanup.expired.deleted', [
            'attachments' => \count($attachmentIds),
            'olderThanDays' => $config->attachmentRetentionDays,
        ]);
    }

    /**
     * @return array<string, string> Schlüssel ist die Anhang-ID, Wert die Medien-ID
     */
    private function fetchExpired(string $cutoff): array
    {
        // Nur die Live-Fassung der Bestellung: Zeilen an einer Entwurfsversion verschwinden mit ihr.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT LOWER(HEX(id)) AS id, LOWER(HEX(media_id)) AS media_id
            FROM `rc_order_attachment`
            WHERE created_at < :cutoff
              AND order_version_id = UNHEX(:liveVersion)
            LIMIT :limit
            SQL,
            [
                'cutoff' => $cutoff,
                'liveVersion' => Defaults::LIVE_VERSION,
                'limit' => self::BATCH_SIZE,
            ],
            ['limit' => ParameterType::INTEGER],
        );

        $map = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $mediaId = (string) ($row['media_id'] ?? '');
            if (Uuid::isValid($id) && Uuid::isValid($mediaId)) {
                $map[$id] = $mediaId;
            }
        }

        return $map;
    }

    /**
     * @param list<string> $attachmentIds
     */
    private function deleteAttachments(array $attachmentIds, Context $context): void
    {
        if ($attachmentIds === []) {
            return;
        }

        $payload = array_map(static fn (string $id): array => ['id' => $id], $attachmentIds);

        try {
            $this->attachmentRepository->delete($payload, $context);
        } catch (Throwable $exception) {
            // Kein Abbruch: Scheitert das Löschen, zeigen die Zeilen weiter auf ihre Medien, und
            // der folgende Schritt lässt diese Medien stehen.
            $this->logger->error('rc_order_attachment.cleanup.expired.attachment_delete_failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param list<string> $mediaIds
     */
    private function deleteUnreferencedMedia(array $mediaIds, Context $context): void
    {
        if ($mediaIds === []) {
            return;
        }

        $unreferenced = $this->filterUnreferenced($mediaIds);
        if ($unreferenced === []) {
            return;
        }

        $payload = array_map(static fn (string $id): array => ['id' => $id], $unreferenced);

        try {
            $this->mediaRepository->delete($payload, $context);
        } catch (Throwable $exception) {
            $this->logger->error('rc_order_attachment.cleanup.expired.media_delete_failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param list<string> $mediaIds
     *
     * @return list<string>
     */
    private function filterUnreferenced(array $mediaIds): array
    {
        $binaryIds = array_map(Uuid::fromHexToBytes(...), $mediaIds);

        $referenced = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(media_id)) FROM `rc_order_attachment` WHERE media_id IN (:ids)',
            ['ids' => $binaryIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $referencedSet = array_flip(array_map(static fn ($v): string => (string) $v, $referenced));

        return array_values(array_filter($mediaIds, static fn (string $id): bool => !isset($referencedSet[$id])));
    }
}
