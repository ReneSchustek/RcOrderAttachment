<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\ScheduledTask;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Installer\MediaFolderInstaller;
use Ruhrcoder\RcOrderAttachment\ScheduledTask\OrphanedAttachmentCleanupTaskHandler;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use RuntimeException;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Unit-Tests der Kontrollflüsse des Orphan-Cleanups (Guard-Branches, destruktiver
 * Delete-Pfad, Fail-Soft). Die SQL-Korrektheit des Orphan-Selects (LEFT JOIN auf
 * `rc_order_attachment`, Cutoff) ist per Mock nicht prüfbar und bleibt einem
 * Integration-Test vorbehalten.
 */
final class OrphanedAttachmentCleanupTaskHandlerTest extends TestCase
{
    private const FOLDER_ID = 'ffffffffffffffffffffffffffffffff';
    private const MEDIA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const MEDIA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * Was: Der Plugin-Media-Folder existiert nicht.
     * Warum: Ohne Folder gibt es keine Orphans zu räumen — es darf nichts gelöscht
     *        werden (Schutz vor versehentlichem Löschen fremder Media).
     * Erwartet: Kein `media`-Delete.
     */
    public function testNoFolderSkipsDeletion(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchFirstColumn');

        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->expects(self::never())->method('delete');

        $this->handler($connection, $mediaRepository, folderId: null)->run();
    }

    /**
     * Was: Folder existiert, aber der Orphan-Select liefert nichts.
     * Warum: Leeres Ergebnis darf keinen leeren Delete auslösen.
     * Erwartet: Kein `media`-Delete.
     */
    public function testEmptyResultSkipsDeletion(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([]);

        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->expects(self::never())->method('delete');

        $this->handler($connection, $mediaRepository)->run();
    }

    /**
     * Was: Der Orphan-Select liefert zwei verwaiste Media-IDs.
     * Warum: DESTRUKTIVER Pfad — genau diese IDs (und nur diese) müssen gelöscht
     *        werden. Gegenprobe: fehlte der Delete-Aufruf, bliebe der Test rot.
     * Erwartet: `media.delete` mit beiden IDs als Payload.
     */
    public function testDeletesOrphanedMedia(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([self::MEDIA_A, self::MEDIA_B]);

        $deleted = null;
        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->expects(self::once())->method('delete')->willReturnCallback(
            static function (array $payload) use (&$deleted) {
                $deleted = $payload;

                return null;
            },
        );

        $this->handler($connection, $mediaRepository)->run();

        self::assertSame([['id' => self::MEDIA_A], ['id' => self::MEDIA_B]], $deleted);
    }

    /**
     * Was: Das Media-Delete wirft.
     * Warum: Ein Cleanup-Fehler darf den Cron nicht abbrechen (der nächste Lauf
     *        holt es nach) — Fail-Soft mit Log.
     * Erwartet: Keine Exception propagiert.
     */
    public function testDeleteFailureIsSwallowed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([self::MEDIA_A]);

        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->method('delete')->willThrowException(new RuntimeException('db down'));

        $this->handler($connection, $mediaRepository)->run();

        self::assertTrue(true, 'run() darf trotz Delete-Fehler nicht werfen');
    }

    /**
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    private function handler(
        Connection $connection,
        EntityRepository $mediaRepository,
        ?string $folderId = self::FOLDER_ID,
    ): OrphanedAttachmentCleanupTaskHandler {
        $idResult = $this->createMock(IdSearchResult::class);
        $idResult->method('firstId')->willReturn($folderId);

        $folderRepository = $this->createMock(EntityRepository::class);
        $folderRepository->method('searchIds')->willReturn($idResult);

        $folderInstaller = new MediaFolderInstaller(
            $folderRepository,
            $this->createMock(EntityRepository::class),
        );

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturn(null);

        return new OrphanedAttachmentCleanupTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            $connection,
            $mediaRepository,
            $folderInstaller,
            new PluginConfigProvider($systemConfig),
            new NullLogger(),
        );
    }
}
