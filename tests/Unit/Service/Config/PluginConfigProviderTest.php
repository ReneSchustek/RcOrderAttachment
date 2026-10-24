<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Config;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Prüft den Konfigurationsleser, die einzige Stelle, an der Eingaben aus der Verwaltung
 * bereinigt werden: Vorgabewerte, Grenzen der Wertebereiche, Normalisierung der Endungen und
 * die Freigabeliste der Mailvorlagen. Was hier durchrutscht, erreicht jeden Validator ungefiltert.
 */
final class PluginConfigProviderTest extends TestCase
{
    /**
     * Nach der Installation ist noch nichts eingestellt. Die Vorgabewerte müssen dann für den
     * Betrieb taugen, sonst läuft das Plugin gar nicht oder mit unsicheren Werten.
     */
    public function testDefaultsWhenEverythingMissing(): void
    {
        $provider = new PluginConfigProvider($this->stubSystemConfig([]));
        $config = $provider->getForSalesChannel();

        self::assertTrue($config->enabled);
        self::assertFalse($config->required);
        self::assertSame(5, $config->maxFiles);
        // 10 MB je Datei und 40 MB gesamt reichen für Handyfotos, die meist 3 bis 8 MB groß sind.
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
     * Zu große Grenzen, versehentlich oder böswillig eingetragen, könnten Platte oder Speicher
     * füllen. Die Konstanten `PluginConfig::ABSOLUTE_MAX_*` deckeln sie hart.
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
     * Null oder ein negativer Wert wiese jede Datei ab, als wäre das Plugin abgeschaltet. Der
     * Leser hebt solche Werte auf 1 an, statt den Fall an jeder Verwendungsstelle abzufangen.
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
     * In der Verwaltung tippt jeder anders: `.PDF`, `PDF` und `pdf` meinen dasselbe, und
     * Komma, Semikolon und Leerzeichen trennen gleichermaßen. Doppelte fallen weg.
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
     * Auch eine manipulierte Systemkonfiguration darf nur saubere Endungen liefern. `j s` und
     * `p df` zerfallen am Leerzeichen in Teile unter drei Zeichen, `html<` scheitert am Muster.
     * `php` besteht das Muster, fällt aber an der Sperrliste, damit es nicht über den
     * `MediaWhitelistSubscriber` in die Freigabeliste des Kerns gelangt.
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
     * Der `MediaWhitelistSubscriber` übernimmt `allowedExtensions` in die Freigabeliste des
     * Kerns. Ohne Sperrliste an der Quelle gäbe eine eingetragene gefährliche Endung wie `svg`
     * diese Dateien shopweit für private Medien frei, ohne dass der `DangerousContentValidator`
     * sie je sähe.
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
     * Eine leere Freigabeliste hieße „an alle Bestellmails anhängen". Besteht die Eingabe nur
     * aus ungültigen Einträgen, gilt deshalb die sichere Vorgabe und nicht „alle".
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
     * Bei den Anhängen ist 0 Tage eine gewollte Einstellung („nie löschen", etwa wegen einer
     * Aufbewahrungspflicht). Bei den Waisen löschte 0 Stunden laufende Uploads sofort, deshalb
     * gilt dort mindestens eine Stunde.
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
