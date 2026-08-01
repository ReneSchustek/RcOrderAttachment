<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Config;

use Ruhrcoder\RcOrderAttachment\Service\Validation\DangerousContentValidator;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Liest die SystemConfig-Werte des Plugins und liefert ein bereinigtes {@see PluginConfig}.
 *
 * Bereinigung erfolgt hier (zentral), nicht in jedem Caller:
 * - Numerische Limits werden geklemmt (lower/upper bound)
 * - Endungs-Whitelist wird normalisiert (lowercase, getrimmt, leer entfernt)
 * - Defensive Defaults bei fehlenden/falsch-typisierten Werten
 */
final class PluginConfigProvider
{
    public const DOMAIN = 'RcOrderAttachment.config.';

    private const DEFAULTS = [
        'enabled' => true,
        'required' => false,
        'maxFiles' => 5,
        // Smartphone-tauglich: iPhone-Fotos liegen oft bei 3–8 MB, ProRAW über 25 MB.
        'maxFileSizeKb' => 10240,
        'maxTotalSizeKb' => 40960,
        'allowedExtensions' => 'pdf,jpg,jpeg,png,webp,heic,heif',
        'orphanRetentionHours' => 24,
        'attachmentRetentionDays' => 180,
        'attachToConfirmationMail' => true,
        'stripExifFromImages' => true,
        'rateLimitPerMinute' => 30,
        'mailTemplateWhitelist' => 'order_confirmation_mail',
        'enableMailRetry' => false,
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public function getForSalesChannel(?string $salesChannelId = null): PluginConfig
    {
        return new PluginConfig(
            enabled: $this->readBool('enabled', $salesChannelId),
            required: $this->readBool('required', $salesChannelId),
            maxFiles: $this->clamp(
                $this->readInt('maxFiles', $salesChannelId),
                1,
                PluginConfig::ABSOLUTE_MAX_FILES,
            ),
            maxFileSizeKb: $this->clamp(
                $this->readInt('maxFileSizeKb', $salesChannelId),
                1,
                PluginConfig::ABSOLUTE_MAX_FILE_SIZE_KB,
            ),
            maxTotalSizeKb: $this->clamp(
                $this->readInt('maxTotalSizeKb', $salesChannelId),
                1,
                PluginConfig::ABSOLUTE_MAX_TOTAL_SIZE_KB,
            ),
            allowedExtensions: $this->parseExtensions($this->readString('allowedExtensions', $salesChannelId)),
            orphanRetentionHours: max(1, $this->readInt('orphanRetentionHours', $salesChannelId)),
            attachmentRetentionDays: max(0, $this->readInt('attachmentRetentionDays', $salesChannelId)),
            attachToConfirmationMail: $this->readBool('attachToConfirmationMail', $salesChannelId),
            stripExifFromImages: $this->readBool('stripExifFromImages', $salesChannelId),
            rateLimitPerMinute: max(0, $this->readInt('rateLimitPerMinute', $salesChannelId)),
            mailTemplateWhitelist: $this->parseTemplateNames($this->readString('mailTemplateWhitelist', $salesChannelId)),
            enableMailRetry: $this->readBool('enableMailRetry', $salesChannelId),
        );
    }

    /**
     * @return list<string>
     */
    private function parseTemplateNames(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw);
        if ($parts === false) {
            $parts = [];
        }
        $cleaned = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || preg_match('/^[a-zA-Z0-9_.\-]{1,128}$/', $part) !== 1) {
                continue;
            }
            $cleaned[$part] = $part;
        }

        // Fail-closed: liefert die Admin-Eingabe (nicht-leer) ausschließlich
        // ungültige Tokens, würde eine leere Whitelist „an ALLE Order-Mails
        // anhängen" bedeuten (Mail-Inflation). Stattdessen auf den sicheren
        // Default zurückfallen.
        if ($cleaned === []) {
            return [self::DEFAULTS['mailTemplateWhitelist']];
        }

        return array_values($cleaned);
    }

    private function readBool(string $key, ?string $salesChannelId): bool
    {
        $value = $this->systemConfigService->get(self::DOMAIN . $key, $salesChannelId);
        if ($value === null) {
            return (bool) self::DEFAULTS[$key];
        }

        return (bool) $value;
    }

    private function readInt(string $key, ?string $salesChannelId): int
    {
        $value = $this->systemConfigService->get(self::DOMAIN . $key, $salesChannelId);
        if ($value === null || $value === '') {
            return (int) self::DEFAULTS[$key];
        }

        return (int) $value;
    }

    private function readString(string $key, ?string $salesChannelId): string
    {
        $value = $this->systemConfigService->get(self::DOMAIN . $key, $salesChannelId);
        if (!\is_string($value) || trim($value) === '') {
            return (string) self::DEFAULTS[$key];
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function parseExtensions(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', strtolower($raw));
        if ($parts === false) {
            $parts = [];
        }
        $cleaned = [];
        foreach ($parts as $part) {
            $part = trim($part, ". \t\n\r\0\x0B");
            // Mindestlänge 3: alle sinnvollen Datei-Endungen sind ≥ 3 Zeichen
            // (`pdf`, `jpg`, `png`, ...). Endungen mit 1–2 Zeichen (`js`, `pl`,
            // `rb`) sind ohnehin durch die hart kodierte Blacklist im
            // {@see DangerousContentValidator} blockiert.
            if ($part === '' || preg_match('/^[a-z0-9]{3,16}$/', $part) !== 1) {
                continue;
            }
            // Blacklist an der Quelle: gefährliche Endungen (php, svg, html, …)
            // werden hier verworfen, damit sie nicht über den
            // MediaWhitelistSubscriber in die Core-Media-Whitelist gelangen.
            // Der DangerousContentValidator prüft zusätzlich pro Datei.
            if (\in_array($part, DangerousContentValidator::FORBIDDEN_EXTENSIONS, true)) {
                continue;
            }
            $cleaned[$part] = $part;
        }

        return array_values($cleaned);
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
