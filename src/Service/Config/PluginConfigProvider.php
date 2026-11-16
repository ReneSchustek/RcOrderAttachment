<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Config;

use Ruhrcoder\RcOrderAttachment\Service\Validation\DangerousContentValidator;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Liest die Konfigurationswerte des Plugins und liefert ein bereinigtes {@see PluginConfig}.
 *
 * Die Bereinigung sitzt an dieser einen Stelle statt in jedem Aufrufer. Zahlen werden in ihre
 * Grenzen geklemmt, die Endungsliste kleingeschrieben, beschnitten und von leeren Einträgen
 * befreit, und fehlende Werte fallen auf {@see DEFAULTS} zurück.
 */
final class PluginConfigProvider
{
    public const DOMAIN = 'RcOrderAttachment.config.';

    // Rückfallwerte, wenn ein Schlüssel fehlt. Sie entsprechen den Vorgaben in config.xml und
    // greifen etwa vor dem ersten Speichern der Konfiguration in einem neuen Verkaufskanal.
    private const DEFAULTS = [
        'enabled' => true,
        'required' => false,
        'maxFiles' => 5,
        // 10 MB reichen für Smartphone-Fotos, die meist bei 3 bis 8 MB liegen; ProRAW-Aufnahmen über
        // 25 MB fallen bewusst heraus.
        'maxFileSizeKb' => 10240,
        // 40 MB lassen vier volle Fotos zu; mehr Dateien gehen nur, wenn sie kleiner sind.
        'maxTotalSizeKb' => 40960,
        'allowedExtensions' => 'pdf,jpg,jpeg,png,webp,heic,heif',
        'orphanRetentionHours' => 24,
        'attachmentRetentionDays' => 180,
        'attachToConfirmationMail' => true,
        'stripExifFromImages' => true,
        'rateLimitPerMinute' => 30,
        'mailTemplateWhitelist' => 'order_confirmation_mail',
        'enableMailRetry' => false,
        'shopMailRecipient' => '',
        // 20 MB nehmen die meisten Postfächer an, mit der Kodierung werden daraus rund 27 MB.
        'shopMailMaxSizeKb' => 20480,
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
            shopMailRecipient: $this->readString('shopMailRecipient', $salesChannelId),
            shopMailMaxSizeKb: max(0, $this->readInt('shopMailMaxSizeKb', $salesChannelId)),
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

        // Besteht die Eingabe nur aus ungültigen Namen, ergäbe sich eine leere Liste, und die hieße
        // „an alle Bestellmails anhängen". Dann gilt lieber die enge Vorgabe.
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
            // Mindestens drei Zeichen, weil alle vorgesehenen Formate so lang sind (`pdf`, `jpg`,
            // `png`). Kürzere Endungen wie `js`, `sh`, `py` oder `pl` sind Skripte und fallen so
            // schon hier heraus; eine erwünschte zweistellige Endung wie `7z` ließe sich allerdings
            // auch nicht freischalten.
            if ($part === '' || preg_match('/^[a-z0-9]{3,16}$/', $part) !== 1) {
                continue;
            }
            // Gefährliche Endungen (php, svg, html) fallen schon hier heraus, damit sie nicht über den
            // MediaWhitelistSubscriber in die Endungsliste des Kerns gelangen. Der
            // DangerousContentValidator prüft zusätzlich jede einzelne Datei.
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
