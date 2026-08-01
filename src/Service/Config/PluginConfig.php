<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Config;

/**
 * Immutables, typsicheres Abbild der Plugin-Konfiguration.
 *
 * Erzeugt wird das Objekt ausschließlich vom {@see PluginConfigProvider} —
 * dort sitzt auch die Bereinigung (Clamping, Whitelist-Parsing).
 */
final readonly class PluginConfig
{
    public const ABSOLUTE_MAX_FILES = 50;
    public const ABSOLUTE_MAX_FILE_SIZE_KB = 102400; // 100 MB
    public const ABSOLUTE_MAX_TOTAL_SIZE_KB = 512000; // 500 MB

    /**
     * @param list<string> $allowedExtensions kleingeschrieben, ohne Punkt
     * @param list<string> $mailTemplateWhitelist technische Mail-Template-Namen; leer = an alle Order-Mails
     */
    public function __construct(
        public bool $enabled,
        public bool $required,
        public int $maxFiles,
        public int $maxFileSizeKb,
        public int $maxTotalSizeKb,
        public array $allowedExtensions,
        public int $orphanRetentionHours,
        public int $attachmentRetentionDays,
        public bool $attachToConfirmationMail,
        public bool $stripExifFromImages,
        public int $rateLimitPerMinute,
        public array $mailTemplateWhitelist,
        public bool $enableMailRetry = false,
    ) {
    }

    public function mailTemplateWhitelistEmpty(): bool
    {
        return $this->mailTemplateWhitelist === [];
    }

    /**
     * Prüft mehrere Kandidaten gegen die Whitelist — in der Praxis den
     * `technicalName` des Mail-Template-Types (`order_confirmation_mail`) und
     * als Zweitkandidat den Flow-Event-Namen (`checkout.order.placed`). Beide
     * Schreibweisen sind im Admin-Feld zulässig; ein Treffer genügt.
     *
     * Leere Whitelist bedeutet „an alle Order-Mails anhängen".
     */
    public function isMailTemplateAllowed(?string ...$candidates): bool
    {
        if ($this->mailTemplateWhitelistEmpty()) {
            return true;
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== null && $candidate !== '' && \in_array($candidate, $this->mailTemplateWhitelist, true)) {
                return true;
            }
        }

        return false;
    }

    public function maxFileSizeBytes(): int
    {
        return $this->maxFileSizeKb * 1024;
    }

    public function maxTotalSizeBytes(): int
    {
        return $this->maxTotalSizeKb * 1024;
    }

    public function isExtensionAllowed(string $extension): bool
    {
        return \in_array(strtolower($extension), $this->allowedExtensions, true);
    }

    public function attachmentRetentionEnabled(): bool
    {
        return $this->attachmentRetentionDays > 0;
    }

    public function rateLimitEnabled(): bool
    {
        return $this->rateLimitPerMinute > 0;
    }
}
