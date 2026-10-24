<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Subscriber;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Media\OrderAttachmentUploadScope;
use Ruhrcoder\RcOrderAttachment\Service\Subscriber\MediaWhitelistSubscriber;
use Shopware\Core\Content\Media\Event\MediaFileExtensionWhitelistEvent;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;

/**
 * Prüft den Subscriber, der die Freigabeliste des Kerns für Medien um die freigegebenen Endungen
 * erweitert. Er wirkt nur für Uploads dieses Plugins, und die Endungen kommen aus dem Kennzeichen,
 * also aus der Konfiguration des Kanals, in dem hochgeladen wird. Der Kern löst das Ereignis bei
 * jedem Medienupload im Shop aus; ohne die Herkunftsprüfung würde die Plugin-Einstellung zur
 * Shop-Einstellung. Gefährliche Endungen entfernt schon der Konfigurationsleser
 * (`PluginConfigProviderTest`).
 */
final class MediaWhitelistSubscriberTest extends TestCase
{
    /**
     * Ohne Erweiterung lehnt Shopwares `FileSaver` eingestellte Endungen wie `dwg` ab, die nicht
     * in `private_allowed_extensions` stehen. Doppelte wie `pdf` erscheinen nur einmal.
     */
    public function testMergesTheScopesExtensionsIntoTheWhitelist(): void
    {
        $event = $this->pluginUploadEvent(['pdf', 'jpg'], ['dwg', 'pdf']);
        (new MediaWhitelistSubscriber())->onWhitelist($event);

        self::assertContains('dwg', $event->getWhitelist());
        self::assertContains('jpg', $event->getWhitelist());
        self::assertCount(1, array_keys($event->getWhitelist(), 'pdf', true));
    }

    /**
     * Ist `dwg` nur in einem Kanal freigegeben, muss es genau dort durchgehen. Das Ereignis kennt keinen
     * Kanal, deshalb trägt das Kennzeichen die Endungen; ohne es gälte die kanalübergreifende
     * Konfiguration, und die Datei scheiterte im Kern mit 500.
     */
    public function testAnExtensionReleasedOnlyInTheChannelPasses(): void
    {
        $event = $this->pluginUploadEvent(['pdf'], ['dwg']);
        (new MediaWhitelistSubscriber())->onWhitelist($event);

        self::assertContains('dwg', $event->getWhitelist());
    }

    /**
     * Ohne Herkunftsmarke, also bei jedem anderen Upload im Shop, bleibt die Liste unverändert. Sonst
     * wäre ein für Kundenanhänge eingetragenes `zip` shopweit erlaubt, auch für öffentliche Medien.
     */
    public function testForeignUploadLeavesWhitelistUntouched(): void
    {
        $event = new MediaFileExtensionWhitelistEvent(['pdf'], new Context(new SystemSource()));
        (new MediaWhitelistSubscriber())->onWhitelist($event);

        self::assertSame(['pdf'], $event->getWhitelist());
    }

    /**
     * Ein Ereignis ohne Kontext lässt sich bis Shopware 6.8 noch bauen. Ohne Kontext ist die
     * Herkunft nicht belegbar, und im Zweifel ist ein abgelehnter Plugin-Upload besser als eine
     * stille shopweite Freigabe. Die Ausnahme aus `getContext()` darf dabei nicht durchschlagen,
     * sonst scheiterte jeder Medienupload im Shop.
     */
    public function testEventWithoutContextLeavesWhitelistUntouched(): void
    {
        $event = new MediaFileExtensionWhitelistEvent(['pdf']);
        (new MediaWhitelistSubscriber())->onWhitelist($event);

        self::assertSame(['pdf'], $event->getWhitelist());
    }

    public function testAScopeWithoutExtensionsLeavesWhitelistUntouched(): void
    {
        $event = $this->pluginUploadEvent(['pdf'], []);
        (new MediaWhitelistSubscriber())->onWhitelist($event);

        self::assertSame(['pdf'], $event->getWhitelist());
    }

    /**
     * Ereignis mit markiertem Kontext, so wie der Uploaddienst des Plugins es auslöst.
     *
     * @param list<string> $whitelist
     * @param list<string> $allowedExtensions
     */
    private function pluginUploadEvent(array $whitelist, array $allowedExtensions): MediaFileExtensionWhitelistEvent
    {
        $context = new Context(new SystemSource());
        $context->addExtension(OrderAttachmentUploadScope::NAME, new OrderAttachmentUploadScope($allowedExtensions));

        return new MediaFileExtensionWhitelistEvent($whitelist, $context);
    }
}
