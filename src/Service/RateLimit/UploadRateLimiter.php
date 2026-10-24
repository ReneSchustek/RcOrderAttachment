<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\RateLimit;

use Closure;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Throwable;

/**
 * Begrenzt die Uploads je Sitzung über einen Zähler im Shopware-Cache.
 *
 * Das Fenster beginnt mit dem ersten Upload und endet 60 Sekunden danach; der Zähler merkt sich
 * diesen Anfang. Liefe die Ablaufzeit mit jedem Upload neu an, bliebe gesperrt, wer gleichmäßig in
 * kürzeren Abständen hochlädt, auch weit über die Minute hinaus.
 *
 * Die RateLimiter-Komponente von Symfony bräuchte eigene Einrichtung und einen eigenen
 * Cache-Pool. Der vorhandene Shopware-Cache läuft dagegen in jeder Umgebung (Datei, Redis, APCu).
 *
 * Bei einem Cache-Fehler geht der Upload durch, und das Protokoll vermerkt es. Die Grenze ist eine
 * Bremse gegen Missbrauch aus einer Sitzung; den eigentlichen Schutz vor Überlast leisten
 * Webserver und vorgeschaltete Firewall.
 */
final class UploadRateLimiter
{
    // Passt zur Einheit der Einstellung `rateLimitPerMinute`.
    private const WINDOW_SECONDS = 60;
    private const CACHE_KEY_PREFIX = 'rc_order_attachment.ratelimit.';

    /**
     * @param Closure(): int|null $clock liefert die Zeit in Sekunden; nur die Tests setzen ihn
     */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly ?Closure $clock = null,
    ) {
    }

    /**
     * Zählt einen Upload. Liefert `false`, wenn die Grenze erreicht ist, und `true` sonst, auch
     * wenn der Cache nicht erreichbar ist.
     */
    public function consume(string $sessionId, PluginConfig $config): bool
    {
        if (!$config->rateLimitEnabled()) {
            return true;
        }

        // Gehasht, damit die Sitzungs-ID weder im Cache-Schlüssel noch im Protokoll im Klartext steht.
        $key = self::CACHE_KEY_PREFIX . hash('sha256', $sessionId);

        try {
            $now = $this->clock !== null ? ($this->clock)() : time();
            $item = $this->cache->getItem($key);
            [$count, $windowStart] = $this->window($item->isHit() ? $item->get() : null, $now);

            if ($count >= $config->rateLimitPerMinute) {
                $this->logger->warning('rc_order_attachment.ratelimit.exceeded', [
                    'sessionHash' => substr($key, -16),
                    'count' => $count,
                    'limit' => $config->rateLimitPerMinute,
                ]);

                return false;
            }

            // Lesen und Schreiben sind nicht atomar. Parallele Anfragen können einzelne Uploads
            // über die Grenze bringen; für eine Bremse genügt das.
            $item->set([$count + 1, $windowStart]);
            $item->expiresAfter(max(1, $windowStart + self::WINDOW_SECONDS - $now));
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

    /**
     * Zahl und Anfang des laufenden Fensters. Ein abgelaufenes Fenster, das der Cache noch liefert,
     * oder ein Wert in anderer Form beginnt ein neues.
     *
     * @return array{int, int}
     */
    private function window(mixed $stored, int $now): array
    {
        if (\is_array($stored) && \is_int($stored[0] ?? null) && \is_int($stored[1] ?? null)
            && $now - $stored[1] < self::WINDOW_SECONDS) {
            return [$stored[0], $stored[1]];
        }

        return [0, $now];
    }
}
