<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\RateLimit;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Throwable;

/**
 * Cache-basierter Rate-Limiter mit Sliding-Window über 60 Sekunden.
 *
 * Bewusst kein Symfony-RateLimiter-Bundle (separates Setup, eigener Cache-Pool
 * nötig). Stattdessen reichen wir den vorhandenen Shopware-Cache durch — der
 * läuft in jedem Setup (File, Redis, APCu) und braucht keine zusätzliche Pflege.
 *
 * Fail-Open-Verhalten: bei Cache-Fehlern wird der Upload durchgelassen und
 * geloggt. Der Verteidigungs-Effekt ist nice-to-have, kein Hard-Gate — die
 * eigentliche Verteidigung sitzt im Webserver/WAF (Cloudflare/Nginx-Limit).
 */
final class UploadRateLimiter
{
    private const WINDOW_SECONDS = 60;
    private const CACHE_KEY_PREFIX = 'rc_order_attachment.ratelimit.';

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Versucht, einen Upload-Slot zu reservieren. Liefert `true` bei erlaubtem
     * Upload, `false` bei Limit-Überschreitung. Bei Cache-Fehlern fail-open.
     */
    public function consume(string $sessionId, PluginConfig $config): bool
    {
        if (!$config->rateLimitEnabled()) {
            return true;
        }

        $key = self::CACHE_KEY_PREFIX . hash('sha256', $sessionId);

        try {
            $item = $this->cache->getItem($key);
            $count = $item->isHit() ? (int) $item->get() : 0;

            if ($count >= $config->rateLimitPerMinute) {
                $this->logger->warning('rc_order_attachment.ratelimit.exceeded', [
                    'sessionHash' => substr($key, -16),
                    'count' => $count,
                    'limit' => $config->rateLimitPerMinute,
                ]);

                return false;
            }

            $item->set($count + 1);
            $item->expiresAfter(self::WINDOW_SECONDS);
            $this->cache->save($item);

            return true;
        } catch (Throwable $exception) {
            $this->logger->warning('rc_order_attachment.ratelimit.cache_failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return true;
        }
    }
}
