<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\RateLimit;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Ruhrcoder\RcOrderAttachment\Service\RateLimit\UploadRateLimiter;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Prüft die Uploadbremse je Sitzung: Grenze, Trennung der Sitzungen, Abschalten und das
 * Durchlassen bei Cachefehlern. Sie soll Bots bremsen, ohne je einen echten Kunden auszusperren.
 */
final class UploadRateLimiterTest extends TestCase
{
    /**
     * Mit 0 ist die Bremse aus, etwa wenn eine vorgeschaltete Firewall das übernimmt. 100 Aufrufe
     * liegen weit über jeder sinnvollen Grenze und gehen trotzdem alle durch.
     */
    public function testFailOpenWhenDisabled(): void
    {
        $limiter = new UploadRateLimiter(new ArrayAdapter(), new NullLogger());
        $config = $this->config(rateLimitPerMinute: 0);

        for ($i = 0; $i < 100; $i++) {
            self::assertTrue($limiter->consume('session-x', $config));
        }
    }

    /**
     * Bis einschließlich zur Grenze geht jeder Aufruf durch. Ein Fehler um eins sperrte echte
     * Kunden beim letzten erlaubten Upload aus.
     */
    public function testAllowsUpToLimit(): void
    {
        $limiter = new UploadRateLimiter(new ArrayAdapter(), new NullLogger());
        $config = $this->config(rateLimitPerMinute: 3);

        self::assertTrue($limiter->consume('session-x', $config));
        self::assertTrue($limiter->consume('session-x', $config));
        self::assertTrue($limiter->consume('session-x', $config));
    }

    /**
     * Über der Grenze wird jeder weitere Aufruf abgewiesen, nicht nur der erste, sonst käme
     * ein Bot mit jedem zweiten Versuch durch.
     */
    public function testRejectsBeyondLimit(): void
    {
        $limiter = new UploadRateLimiter(new ArrayAdapter(), new NullLogger());
        $config = $this->config(rateLimitPerMinute: 2);

        self::assertTrue($limiter->consume('session-x', $config));
        self::assertTrue($limiter->consume('session-x', $config));
        self::assertFalse($limiter->consume('session-x', $config));
        self::assertFalse($limiter->consume('session-x', $config));
    }

    /**
     * Das Fenster beginnt beim ersten Upload und verlängert sich nicht. Setzte jeder Upload die
     * Ablaufzeit neu, bliebe gesperrt, wer alle 30 Sekunden hochlädt, hier bis Sekunde 90 statt
     * bis Sekunde 60.
     */
    public function testTheWindowStartsWithTheFirstUploadAndDoesNotExtend(): void
    {
        $now = 1000;
        $limiter = new UploadRateLimiter(new ArrayAdapter(), new NullLogger(), static function () use (&$now): int {
            return $now;
        });
        $config = $this->config(rateLimitPerMinute: 2);

        self::assertTrue($limiter->consume('session-x', $config));
        $now = 1030;
        self::assertTrue($limiter->consume('session-x', $config));
        $now = 1059;
        self::assertFalse($limiter->consume('session-x', $config), 'Innerhalb der ersten Minute gilt die Grenze.');
        $now = 1060;
        self::assertTrue($limiter->consume('session-x', $config), 'Eine Minute nach dem ersten Upload beginnt ein neues Fenster.');
    }

    /**
     * Die Grenze gilt je Sitzung. Ein Bot in der einen Sitzung darf Kunden in einer anderen nicht
     * aussperren.
     */
    public function testIsolatesPerSession(): void
    {
        $limiter = new UploadRateLimiter(new ArrayAdapter(), new NullLogger());
        $config = $this->config(rateLimitPerMinute: 1);

        self::assertTrue($limiter->consume('session-a', $config));
        self::assertFalse($limiter->consume('session-a', $config));

        self::assertTrue($limiter->consume('session-b', $config));
    }

    /**
     * Fällt der Cache aus, etwa Redis, geht der Aufruf durch. Die Bremse ist eine Ergänzung,
     * der eigentliche Schutz sitzt im Webserver, und ein Cacheausfall darf keine Kunden aussperren.
     */
    public function testFailOpenOnCacheException(): void
    {
        $brokenCache = $this->createMock(CacheItemPoolInterface::class);
        $brokenCache->method('getItem')->willThrowException(new RuntimeException('cache down'));

        $limiter = new UploadRateLimiter($brokenCache, new NullLogger());
        $config = $this->config(rateLimitPerMinute: 1);

        self::assertTrue($limiter->consume('session-x', $config));
    }

    /**
     * Beim ersten Upload einer Sitzung gibt es noch keinen Zähler. Er beginnt bei 1 und läuft
     * nach 60 Sekunden ab, der Länge des Fensters, auf das sich „je Minute" bezieht.
     */
    public function testItemMissReturnsTrue(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('get')->willReturn(null);
        // Gespeichert werden Zahl und Anfang des Fensters; das neue Fenster läuft volle 60 Sekunden.
        $item->expects(self::once())->method('set')->with([1, 1000])->willReturnSelf();
        $item->expects(self::once())->method('expiresAfter')->with(60)->willReturnSelf();
        $cache->method('getItem')->willReturn($item);
        $cache->expects(self::once())->method('save')->with($item);

        $limiter = new UploadRateLimiter($cache, new NullLogger(), static fn (): int => 1000);

        self::assertTrue($limiter->consume('session-x', $this->config(rateLimitPerMinute: 5)));
    }

    private function config(
        int $rateLimitPerMinute = 30,
    ): PluginConfig {
        return new PluginConfig(
            enabled: true,
            required: false,
            maxFiles: 5,
            maxFileSizeKb: 10240,
            maxTotalSizeKb: 40960,
            allowedExtensions: ['pdf'],
            orphanRetentionHours: 24,
            attachmentRetentionDays: 180,
            attachToConfirmationMail: true,
            stripExifFromImages: true,
            rateLimitPerMinute: $rateLimitPerMinute,
            mailTemplateWhitelist: [],
        );
    }
}
