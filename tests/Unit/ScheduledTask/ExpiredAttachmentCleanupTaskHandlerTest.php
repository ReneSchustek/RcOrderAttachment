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
 * Unit-Tests der Kontrollflüsse des Retention-Cleanups: Retention-Aus-Guard,
 * Leer-Guard, destruktiver Delete-Pfad und — sicherheitskritisch — dass ein noch
 * referenziertes (geteiltes) Media NICHT gelöscht wird. Die SQL-Korrektheit des
 * Expired-Selects bleibt einem Integration-Test vorbehalten.
 */
final class ExpiredAttachmentCleanupTaskHandlerTest extends TestCase
{
    private const ATT_1 = '11111111111111111111111111111111';
    private const MEDIA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * Was: `attachmentRetentionDays = 0` (Retention deaktiviert, „nie löschen").
     * Warum: 0 ist die explizite „nie löschen"-Konfiguration (Audit-Pflicht) —
     *        der Cleanup darf dann NICHTS anfassen.
     * Erwartet: Weder DB-Query noch Delete.
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
     * Was: Kein abgelaufener Anhang.
     * Warum: Leeres Ergebnis darf keinen Delete auslösen.
     * Erwartet: Kein Delete.
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
     * Was: Ein abgelaufener Anhang, dessen Media von keiner anderen Order
     *      referenziert wird.
     * Warum: DESTRUKTIVER Happy-Path — Anhang UND Media müssen gelöscht werden.
     * Erwartet: `attachment.delete` mit dem Anhang, `media.delete` mit dem Media.
     */
    public function testDeletesExpiredAttachmentAndUnreferencedMedia(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['id' => self::ATT_1, 'media_id' => self::MEDIA_A]]);
        // filterUnreferenced: kein referenzierendes rc_order_attachment mehr
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
     * Was: Abgelaufener Anhang, dessen Media aber noch von einer ANDEREN Order
     *      referenziert wird (Cart-Split-Sharing).
     * Warum: SICHERHEITSKRITISCH — das geteilte Media darf nicht gelöscht werden,
     *        sonst reisst der FK `ON DELETE CASCADE` den Anhang der anderen Order
     *        mit. Der abgelaufene Anhang selbst wird trotzdem entfernt.
     * Erwartet: `attachment.delete` ja, `media.delete` NEIN.
     */
    public function testKeepsMediaStillReferencedByAnotherOrder(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([['id' => self::ATT_1, 'media_id' => self::MEDIA_A]]);
        // filterUnreferenced: Media ist noch referenziert -> bleibt
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
