<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Config;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Unit-Tests des Konfig-Providers: Default-Fallbacks, Wertebereich-Clamping,
 * Whitelist-Normalisierung, Mail-Template-Parsing. Sichert die zentrale
 * Bereinigungs-Stelle gegen Regressionen.
 */
final class PluginConfigProviderTest extends TestCase
{
    /**
     * Was: SystemConfigService liefert für alle Keys `null`.
     * Warum: Beim Erst-Install hat der Operator noch nichts konfiguriert — die
     *        Plugin-Defaults müssen Production-tauglich sein, sonst läuft das
     *        Plugin out-of-the-box gar nicht oder mit unsicheren Werten.
     * Erwartet: Alle Config-Felder fallen auf ihre Defaults aus `PluginConfigProvider`.
     */
    public function testDefaultsWhenEverythingMissing(): void
    {
        $provider = new PluginConfigProvider($this->stubSystemConfig([]));
        $config = $provider->getForSalesChannel();

        self::assertTrue($config->enabled);
        self::assertFalse($config->required);
        self::assertSame(5, $config->maxFiles);
        // B2C-Defaults: Smartphone-tauglich (iPhone-Fotos um 3–8 MB).
        self::assertSame(10240, $config->maxFileSizeKb);
        self::assertSame(40960, $config->maxTotalSizeKb);
        self::assertSame(['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'], $config->allowedExtensions);
        self::assertSame(24, $config->orphanRetentionHours);
        self::assertSame(180, $config->attachmentRetentionDays);
        self::assertTrue($config->attachToConfirmationMail);
        self::assertTrue($config->stripExifFromImages);
        self::assertSame(30, $config->rateLimitPerMinute);
        self::assertSame(['order_confirmation_mail'], $config->mailTemplateWhitelist);
    }

    /**
     * Was: Admin trägt absurd hohe Werte in die Admin-UI ein.
     * Warum: Schutz vor versehentlich oder böswillig zu großen Limits, die
     *        eine Disk-Voll-Situation oder Memory-Explosion auslösen würden.
     *        `ABSOLUTE_MAX_*`-Konstanten sind die harte Obergrenze.
     * Erwartet: Werte werden auf die `PluginConfig::ABSOLUTE_MAX_*` geclampt.
     */
    public function testClampingUpperBound(): void
    {
        $provider = new PluginConfigProvider($this->stubSystemConfig([
            PluginConfigProvider::DOMAIN . 'maxFiles' => 9999,
            PluginConfigProvider::DOMAIN . 'maxFileSizeKb' => 999999999,
            PluginConfigProvider::DOMAIN . 'maxTotalSizeKb' => 999999999,
        ]));
        $config = $provider->getForSalesChannel();

        self::assertSame(PluginConfig::ABSOLUTE_MAX_FILES, $config->maxFiles);
        self::assertSame(PluginConfig::ABSOLUTE_MAX_FILE_SIZE_KB, $config->maxFileSizeKb);
        self::assertSame(PluginConfig::ABSOLUTE_MAX_TOTAL_SIZE_KB, $config->maxTotalSizeKb);
    }

    /**
     * Was: Admin trägt 0 oder negative Werte ein.
     * Warum: 0/negativ bedeutet faktisch „Plugin deaktiviert" für Datei-Limits.
     *        Statt das im Plugin abzufangen, clampen wir auf 1 als Minimum —
     *        kostet keine Komplexität und vermeidet Division-by-Zero im Code.
     * Erwartet: Werte werden auf 1 angehoben.
     */
    public function testClampingLowerBound(): void
    {
        $provider = new PluginConfigProvider($this->stubSystemConfig([
            PluginConfigProvider::DOMAIN . 'maxFiles' => 0,
            PluginConfigProvider::DOMAIN . 'maxFileSizeKb' => -10,
        ]));
        $config = $provider->getForSalesChannel();

        self::assertSame(1, $config->maxFiles);
        self::assertSame(1, $config->maxFileSizeKb);
    }

    /**
     * Was: Roh-Whitelist mit Whitespace, Großbuchstaben, Punkten, Duplikaten.
     * Warum: Admin tippt Werte unterschiedlich ein — `.PDF`, `PDF`, `pdf` sollen
     *        äquivalent sein. Doppelte Einträge dürfen nicht zwei DAL-Filter-
     *        Pässe erzeugen.
     * Erwartet: Lowercase, ohne Punkt, dedupliziert: `['pdf', 'jpg', 'png']`.
     */
    public function testExtensionParsingNormalizesAndDeduplicates(): void
    {
        $provider = new PluginConfigProvider($this->stubSystemConfig([
            PluginConfigProvider::DOMAIN . 'allowedExtensions' => ' .PDF , Jpg ; .pdf  png,, ',
        ]));
        $config = $provider->getForSalesChannel();

        self::assertSame(['pdf', 'jpg', 'png'], $config->allowedExtensions);
    }

    /**
     * Was: Roh-Whitelist mit Bindestrich-Zeichen, Whitespace im Token,
     *      Sonderzeichen (`<`), zu kurzen Tokens, plus die gefährliche Endung
     *      `php`.
     * Warum: Defense-in-Depth — bei manipulierter SystemConfig (z. B. SQL-
     *        Injection in der Admin-UI) muss der Parser strenge Regex-Filterung
     *        anwenden. Zusätzlich greift die Blacklist an der Quelle: `php`
     *        besteht zwar die Regex, wird aber verworfen, damit es nicht über
     *        den MediaWhitelistSubscriber in die Core-Media-Whitelist gelangt.
     * Erwartet: Nur `['pdf']` überlebt (`php` von der Blacklist verworfen,
     *           `j s`/`p df`/`html<` von der Regex).
     */
    public function testExtensionParsingRejectsDangerousChars(): void
    {
        $provider = new PluginConfigProvider($this->stubSystemConfig([
            PluginConfigProvider::DOMAIN . 'allowedExtensions' => 'php,j s,html<,p df,pdf',
        ]));
        $config = $provider->getForSalesChannel();

        self::assertSame(['pdf'], $config->allowedExtensions);
    }

    /**
     * Was: Admin trägt gefährliche, aber formal gültige Endungen (`svg`, `exe`,
     *      `phtml`) neben harmlosen in die Whitelist.
     * Warum: Der MediaWhitelistSubscriber merged `allowedExtensions` in die
     *        Core-Media-Whitelist. Ohne Blacklist-Filter an der Quelle würde
     *        eine admin-konfigurierte gefährliche Endung global für private
     *        Media freigegeben, ohne je den DangerousContentValidator zu
     *        durchlaufen (Fail-open).
     * Erwartet: gefährliche Endungen werden verworfen, nur `['pdf', 'png']`
     *           bleiben.
     */
    public function testExtensionParsingDropsBlacklistedExtensions(): void
    {
        $provider = new PluginConfigProvider($this->stubSystemConfig([
            PluginConfigProvider::DOMAIN . 'allowedExtensions' => 'pdf,svg,exe,phtml,png',
        ]));
        $config = $provider->getForSalesChannel();

        self::assertSame(['pdf', 'png'], $config->allowedExtensions);
    }

    /**
     * Was: `mailTemplateWhitelist` enthält ausschließlich ungültige Tokens.
     * Warum: Eine leere Whitelist würde „an ALLE Order-Mails anhängen"
     *        bedeuten (Mail-Inflation). Bei nur-ungültiger Eingabe
     *        muss fail-closed auf den sicheren Default zurückgefallen werden,
     *        nicht fail-open auf „alle".
     * Erwartet: `['order_confirmation_mail']`.
     */
    public function testMailWhitelistFailsClosedOnAllInvalidTokens(): void
    {
        $provider = new PluginConfigProvider($this->stubSystemConfig([
            PluginConfigProvider::DOMAIN . 'mailTemplateWhitelist' => '###,@@@,!!!',
        ]));
        $config = $provider->getForSalesChannel();

        self::assertSame(['order_confirmation_mail'], $config->mailTemplateWhitelist);
    }

    /**
     * Was: `attachmentRetentionDays = 0` und `orphanRetentionHours = 0`.
     * Warum: `0` bei `attachmentRetentionDays` ist die explizite Konfiguration
     *        „nie löschen" (legitime Anforderung, z. B. Audit-Pflicht).
     *        `orphanRetentionHours = 0` ist dagegen unsinnig — sofortiges
     *        Löschen würde laufende Uploads zerschießen, deshalb min 1.
     * Erwartet: `attachmentRetentionDays = 0` (akzeptiert),
     *           `orphanRetentionHours = 1` (clamped), `attachmentRetentionEnabled() = false`.
     */
    public function testRetentionAllowsZero(): void
    {
        $provider = new PluginConfigProvider($this->stubSystemConfig([
            PluginConfigProvider::DOMAIN . 'attachmentRetentionDays' => 0,
            PluginConfigProvider::DOMAIN . 'orphanRetentionHours' => 0,
        ]));
        $config = $provider->getForSalesChannel();

        self::assertSame(0, $config->attachmentRetentionDays);
        self::assertSame(1, $config->orphanRetentionHours);
        self::assertFalse($config->attachmentRetentionEnabled());
    }

    /**
     * @param array<string, mixed> $map
     */
    private function stubSystemConfig(array $map): SystemConfigService
    {
        $stub = $this->createMock(SystemConfigService::class);
        $stub->method('get')->willReturnCallback(
            static fn (string $key, ?string $salesChannelId = null) => $map[$key] ?? null
        );

        return $stub;
    }
}
