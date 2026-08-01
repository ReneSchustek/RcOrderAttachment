<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\Media\OrderAttachmentUploadScope;
use Ruhrcoder\RcOrderAttachment\Service\Subscriber\MediaWhitelistSubscriber;
use Shopware\Core\Content\Media\Event\MediaFileExtensionWhitelistEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Sichert den sicherheitsrelevanten Whitelist-Subscriber in zwei Richtungen:
 *
 * 1. Er erweitert die Core-Media-Whitelist um die admin-konfigurierten Endungen —
 *    darf dabei aber NIEMALS geblacklistete (gefährliche) Endungen durchreichen.
 * 2. Er tut das ausschließlich für Uploads dieses Plugins. Der Core dispatcht das
 *    Event für jeden Media-Upload im Shop; ohne die Herkunftsprüfung würde die
 *    Plugin-Konfiguration zur Shop-Konfiguration.
 *
 * Echte {@see PluginConfigProvider}-Instanz mit gemocktem SystemConfigService.
 */
final class MediaWhitelistSubscriberTest extends TestCase
{
    /**
     * Was: Konfigurierte Endungen (`dwg`) werden in die Core-Whitelist gemerged.
     * Warum: Ohne den Merge lehnt Shopwares FileSaver zulässige, aber nicht in
     *        `private_allowed_extensions` stehende Endungen ab.
     * Erwartet: Bestehende + konfigurierte Endungen, dedupliziert.
     */
    public function testMergesConfiguredExtensionsIntoWhitelist(): void
    {
        $event = $this->pluginUploadEvent(['pdf', 'jpg']);
        $this->subscriber(['allowedExtensions' => 'dwg,pdf'])->onWhitelist($event);

        self::assertContains('dwg', $event->getWhitelist());
        self::assertContains('jpg', $event->getWhitelist());
        self::assertCount(1, array_keys($event->getWhitelist(), 'pdf', true));
    }

    /**
     * Was: Ein Media-Upload ohne den Herkunfts-Marker am Context — also jeder
     *      andere Upload im Shop (Admin-Medienverwaltung, anderes Plugin).
     * Warum: KERN-SCOPE-NACHWEIS. Vorher erweiterte der Subscriber die Whitelist
     *        für ALLE Uploads. Trug ein Admin `zip` in die Plugin-Config ein,
     *        um Kunden ZIPs an Bestellungen anhängen zu lassen, war `zip`
     *        anschließend shopweit erlaubt — auch für öffentliche Medien.
     * Erwartet: Whitelist unverändert.
     */
    public function testForeignUploadLeavesWhitelistUntouched(): void
    {
        $event = new MediaFileExtensionWhitelistEvent(['pdf'], Context::createDefaultContext());
        $this->subscriber(['allowedExtensions' => 'dwg'])->onWhitelist($event);

        self::assertSame(['pdf'], $event->getWhitelist());
    }

    /**
     * Was: Ein Event ohne Context — bis Shopware 6.8 konstruierbar, danach nicht mehr.
     * Warum: Ohne Context lässt sich die Herkunft nicht belegen. Fail-closed ist
     *        hier Pflicht: Im Zweifel lieber ein abgelehnter Plugin-Upload als
     *        eine stillschweigend shopweite Ausweitung. Zugleich darf der
     *        Subscriber die Exception aus `getContext()` nicht nach oben lassen —
     *        sie würde jeden Media-Upload im Shop scheitern lassen.
     * Erwartet: Whitelist unverändert, keine Exception.
     */
    public function testEventWithoutContextLeavesWhitelistUntouched(): void
    {
        $event = new MediaFileExtensionWhitelistEvent(['pdf']);
        $this->subscriber(['allowedExtensions' => 'dwg'])->onWhitelist($event);

        self::assertSame(['pdf'], $event->getWhitelist());
    }

    /**
     * Was: Plugin deaktiviert.
     * Warum: Ist die Upload-Funktion aus, darf der Subscriber die Core-Whitelist
     *        nicht anfassen (POLS).
     * Erwartet: Whitelist unverändert.
     */
    public function testDisabledPluginLeavesWhitelistUntouched(): void
    {
        $event = $this->pluginUploadEvent(['pdf']);
        $this->subscriber(['enabled' => false, 'allowedExtensions' => 'dwg'])->onWhitelist($event);

        self::assertSame(['pdf'], $event->getWhitelist());
    }

    /**
     * Was: Admin trägt gefährliche Endungen (`svg`, `php`) in die Config.
     * Warum: KERN-SICHERHEITSNACHWEIS — geblacklistete Endungen werden bereits
     *        im PluginConfigProvider verworfen und dürfen nicht über den
     *        Subscriber für private Media freigegeben werden (Fail-open).
     * Erwartet: `svg`/`php` fehlen, harmloses `dwg` ist drin.
     */
    public function testBlacklistedExtensionNeverReachesWhitelist(): void
    {
        $event = $this->pluginUploadEvent(['pdf']);
        $this->subscriber(['allowedExtensions' => 'svg,php,dwg'])->onWhitelist($event);

        self::assertNotContains('svg', $event->getWhitelist());
        self::assertNotContains('php', $event->getWhitelist());
        self::assertContains('dwg', $event->getWhitelist());
    }

    /**
     * Event mit markiertem Context — so, wie der Upload-Service des Plugins es auslöst.
     *
     * @param array<string> $whitelist
     */
    private function pluginUploadEvent(array $whitelist): MediaFileExtensionWhitelistEvent
    {
        $context = Context::createDefaultContext();
        $context->addExtension(OrderAttachmentUploadScope::NAME, new OrderAttachmentUploadScope());

        return new MediaFileExtensionWhitelistEvent($whitelist, $context);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function subscriber(array $overrides): MediaWhitelistSubscriber
    {
        $map = [];
        foreach ($overrides as $key => $value) {
            $map[PluginConfigProvider::DOMAIN . $key] = $value;
        }

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static fn (string $key, ?string $salesChannelId = null) => $map[$key] ?? null,
        );

        return new MediaWhitelistSubscriber(new PluginConfigProvider($systemConfig));
    }
}
