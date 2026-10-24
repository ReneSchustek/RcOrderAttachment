<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Config;

/**
 * Unveränderliches, typisiertes Abbild der Plugin-Konfiguration.
 *
 * Erzeugt wird es nur vom {@see PluginConfigProvider}, der die Werte auch bereinigt (Grenzen,
 * Endungslisten). Die Dienste verlassen sich darauf und prüfen nicht selbst nach.
 */
final readonly class PluginConfig
{
    // Harte Obergrenzen, die auch eine Fehleingabe in der Verwaltung nicht übersteigt. Sie liegen
    // weit über dem Bedarf einer Bestellung mit Zeichnungen und Fotos (Standard 5 Dateien,
    // 10 MB, 40 MB) und begrenzen, wie viel Speicher eine einzelne Sitzung belegen kann.
    // Für Dateien dieser Größe müssen zudem `upload_max_filesize` und `post_max_size` von PHP
    // hoch genug stehen.
    public const ABSOLUTE_MAX_FILES = 50;
    public const ABSOLUTE_MAX_FILE_SIZE_KB = 102400; // 100 MB
    public const ABSOLUTE_MAX_TOTAL_SIZE_KB = 512000; // 500 MB

    /**
     * @param list<string> $allowedExtensions kleingeschrieben, ohne Punkt
     * @param list<string> $mailTemplateWhitelist technische Namen der Mailvorlagen; eine leere Liste hängt an alle Bestellmails an
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
     * Prüft mehrere Kandidaten gegen die erlaubten Vorlagen, in der Praxis den `technicalName` des
     * Mailvorlagentyps (`order_confirmation_mail`) und den Namen des Flow-Ereignisses
     * (`checkout.order.placed`). Beide Schreibweisen sind im Feld der Verwaltung zulässig, ein
     * Treffer genügt. Eine leere Liste erlaubt jede Bestellmail.
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
