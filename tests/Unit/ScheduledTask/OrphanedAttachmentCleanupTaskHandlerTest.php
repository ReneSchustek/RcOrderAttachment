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
 * Prüft den Kontrollfluss der Waisenbereinigung: fehlender Plugin-Ordner, leeres Ergebnis, das
 * Löschen selbst und dass ein Fehler beim Löschen den geplanten Lauf nicht abbricht. Ob die
 * Abfrage die richtigen Zeilen liefert, lässt sich per Mock nicht prüfen; das übernimmt
 * `OrphanedAttachmentCleanupQueryTest`.
 */
final class OrphanedAttachmentCleanupTaskHandlerTest extends TestCase
{
    private const FOLDER_ID = 'ffffffffffffffffffffffffffffffff';
    private const MEDIA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const MEDIA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * Ohne Plugin-Ordner gibt es keine Uploads des Plugins und damit nichts zu löschen; jedes
     * Medium, das der Lauf dann träfe, gehörte jemand anderem.
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
     * Ein leeres Ergebnis darf keinen leeren Löschaufruf auslösen.
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
     * Gelöscht werden genau die IDs, die die Abfrage liefert, und keine anderen.
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
     * Ein Fehler beim Löschen wird protokolliert und bricht den geplanten Lauf nicht ab; der
     * nächste Lauf holt das Löschen nach.
     */
    public function testDeleteFailureIsSwallowed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([self::MEDIA_A]);

        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->method('delete')->willThrowException(new RuntimeException('db down'));

        // Bestanden ist der Test, wenn der Lauf ohne Ausnahme zurückkehrt. PHPUnit erwartet dafür
        // diese Angabe; eine Zusicherung auf `true` prüfte nichts.
        $this->expectNotToPerformAssertions();

        $this->handler($connection, $mediaRepository)->run();
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
