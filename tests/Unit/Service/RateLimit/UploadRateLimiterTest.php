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
 * Unit-Tests für den Cache-basierten Rate-Limiter — inkl. Fail-Open bei
 * Cache-Fehlern, Sliding-Window-Reset, Limit-Boundary.
 */
final class UploadRateLimiterTest extends TestCase
{
    /**
     * Was: `rateLimitPerMinute = 0` (Rate-Limiter deaktiviert).
     * Warum: Operator kann den Limiter abschalten, falls externer WAF/Proxy
     *        die Rate-Limit-Verantwortung übernimmt. Plugin darf dann nichts
     *        blockieren.
     * Erwartet: 100 aufeinanderfolgende `consume` liefern alle `true`.
     */
    public function testFailOpenWhenDisabled(): void
    {
        $limiter = new UploadRateLimiter(new ArrayAdapter(), new NullLogger());
        $config = $this->config(rateLimitPerMinute: 0);

        // Disabled: jeder Aufruf darf durch.
        for ($i = 0; $i < 100; $i++) {
            self::assertTrue($limiter->consume('session-x', $config));
        }
    }

    /**
     * Was: Konfiguriertes Limit von 3, drei Aufrufe.
     * Warum: Innerhalb des Limits müssen alle Anfragen durch — sonst würden
     *        legitime Customer fälschlich blockiert.
     * Erwartet: 3× `true`.
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
     * Was: Limit 2, vier Aufrufe.
     * Warum: Schutz gegen Bot-DoS — über dem Limit muss zuverlässig
     *        zurückgewiesen werden.
     * Erwartet: 2× `true`, danach 2× `false`.
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
     * Was: Zwei verschiedene Session-IDs, beide treffen das Limit 1.
     * Warum: Limit ist pro Session, nicht global — ein Bot in Session-A darf
     *        nicht legitime Customer in Session-B aussperren.
     * Erwartet: Session-A blockiert nach 1, Session-B unabhängig weiterhin OK.
     */
    public function testIsolatesPerSession(): void
    {
        $limiter = new UploadRateLimiter(new ArrayAdapter(), new NullLogger());
        $config = $this->config(rateLimitPerMinute: 1);

        self::assertTrue($limiter->consume('session-a', $config));
        self::assertFalse($limiter->consume('session-a', $config));

        // Andere Session ist unabhängig.
        self::assertTrue($limiter->consume('session-b', $config));
    }

    /**
     * Was: Cache-Pool wirft `RuntimeException` (z. B. Redis down).
     * Warum: Fail-Open-Strategie — der Cache-Limit ist nice-to-have, der
     *        eigentliche Schutz sitzt im Webserver/WAF. Cache-Ausfall darf
     *        legitime Customer NICHT blockieren.
     * Erwartet: `true` trotz Exception (Aufruf wird durchgelassen).
     */
    public function testFailOpenOnCacheException(): void
    {
        $brokenCache = $this->createMock(CacheItemPoolInterface::class);
        $brokenCache->method('getItem')->willThrowException(new RuntimeException('cache down'));

        $limiter = new UploadRateLimiter($brokenCache, new NullLogger());
        $config = $this->config(rateLimitPerMinute: 1);

        // Fail-Open: bei Cache-Fehler durchlassen, nicht blockieren.
        self::assertTrue($limiter->consume('session-x', $config));
    }

    /**
     * Was: Cache-Miss bei neuem Session-Key.
     * Warum: Beim ersten Upload existiert noch kein Counter. Cache-Item wird
     *        mit `set(1)` initialisiert und mit 60-Sekunden-TTL angelegt.
     * Erwartet: `set(1)` mit `expiresAfter(60)`, `save()`, Rückgabe `true`.
     */
    public function testItemMissReturnsTrue(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $item->method('get')->willReturn(null);
        $item->expects(self::once())->method('set')->with(1)->willReturnSelf();
        $item->expects(self::once())->method('expiresAfter')->with(60)->willReturnSelf();
        $cache->method('getItem')->willReturn($item);
        $cache->expects(self::once())->method('save')->with($item);

        $limiter = new UploadRateLimiter($cache, new NullLogger());

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
