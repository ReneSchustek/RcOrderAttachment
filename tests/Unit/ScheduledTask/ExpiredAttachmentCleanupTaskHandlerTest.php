<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\ScheduledTask;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\ScheduledTask\ExpiredAttachmentCleanupTaskHandler;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Prüft den Kontrollfluss der Aufbewahrungsbereinigung: abgeschaltete Aufbewahrung, leeres
 * Ergebnis, das Löschen selbst und vor allem, dass ein noch geteiltes Medium stehen bleibt.
 * Ob die Abfragen die richtigen Zeilen liefern, prüft `ExpiredAttachmentCleanupQueryTest`.
 */
final class ExpiredAttachmentCleanupTaskHandlerTest extends TestCase
{
    private const ATT_1 = '11111111111111111111111111111111';
    private const MEDIA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * 0 Tage bedeutet „nie löschen", etwa wegen einer Aufbewahrungspflicht. Die Bereinigung
     * fragt dann nicht einmal die Datenbank ab.
     */
    public function testDisabledRetentionSkipsEverything(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAllAssociative');

        $attachmentRepository = $this->createMock(EntityRepository::class);
        $attachmentRepository->expects(self::never())->method('delete');

        $this->handler($connection, $attachmentRepository, $this->createMock(EntityRepository::class), retentionDays: 0)->run();
    }

    /**
     * Ohne abgelaufenen Anhang darf kein Löschaufruf abgehen, auch kein leerer.
     */
    public function testEmptyResultSkipsDeletion(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $attachmentRepository = $this->createMock(EntityRepository::class);
        $attachmentRepository->expects(self::never())->method('delete');

        $this->handler($connection, $attachmentRepository, $this->createMock(EntityRepository::class))->run();
    }

    /**
     * Der Regelfall: Zeigt kein anderer Anhang auf das Medium, gehen Anhang und Medium.
     */
    public function testDeletesExpiredAttachmentAndUnreferencedMedia(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['id' => self::ATT_1, 'media_id' => self::MEDIA_A]]);
        // `filterUnreferenced()` findet keine Anhangszeile mehr, die auf das Medium zeigt.
        $connection->method('fetchFirstColumn')->willReturn([]);

        $attDeleted = null;
        $attachmentRepository = $this->createMock(EntityRepository::class);
        $attachmentRepository->expects(self::once())->method('delete')->willReturnCallback(
            static function (array $p) use (&$attDeleted) {
                $attDeleted = $p;
                return null;
            },
        );

        $mediaDeleted = null;
        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->expects(self::once())->method('delete')->willReturnCallback(
            static function (array $p) use (&$mediaDeleted) {
                $mediaDeleted = $p;
                return null;
            },
        );

        $this->handler($connection, $attachmentRepository, $mediaRepository)->run();

        self::assertSame([['id' => self::ATT_1]], $attDeleted);
        self::assertSame([['id' => self::MEDIA_A]], $mediaDeleted);
    }

    /**
     * Zeigt noch ein Anhang einer anderen Bestellung auf das Medium, bleibt es stehen. Sonst
     * risse der Fremdschlüssel mit `ON DELETE CASCADE` jenen Anhang mit. Der abgelaufene Anhang
     * selbst geht trotzdem.
     */
    public function testKeepsMediaStillReferencedByAnotherOrder(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['id' => self::ATT_1, 'media_id' => self::MEDIA_A]]);
        // `filterUnreferenced()` findet das Medium noch in einer Anhangszeile.
        $connection->method('fetchFirstColumn')->willReturn([self::MEDIA_A]);

        $attachmentRepository = $this->createMock(EntityRepository::class);
        $attachmentRepository->expects(self::once())->method('delete');

        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->expects(self::never())->method('delete');

        $this->handler($connection, $attachmentRepository, $mediaRepository)->run();
    }

    /**
     * @param EntityRepository<OrderAttachmentCollection> $attachmentRepository
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    private function handler(
        Connection $connection,
        EntityRepository $attachmentRepository,
        EntityRepository $mediaRepository,
        int $retentionDays = 180,
    ): ExpiredAttachmentCleanupTaskHandler {
        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key, ?string $sc = null) => $key === PluginConfigProvider::DOMAIN . 'attachmentRetentionDays' ? $retentionDays : null,
        );

        return new ExpiredAttachmentCleanupTaskHandler(
            $this->createMock(EntityRepository::class),
            new NullLogger(),
            $connection,
            $attachmentRepository,
            $mediaRepository,
            new PluginConfigProvider($systemConfig),
            new NullLogger(),
        );
    }
}
