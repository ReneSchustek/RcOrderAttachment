<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Ruhrcoder\RcOrderAttachment\RcOrderAttachment;
use Stringable;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * Prüft die Containerzugriffe im Lebenszyklus des Plugins: die richtige Dienst-ID für die
 * Datenbankverbindung und dass ein fehlschlagender Zugriff protokolliert statt weitergeworfen wird.
 *
 * Der Symfony-Alias `database_connection` ist privat, `get()` wirft darauf. Holte `uninstall()`
 * die Verbindung darüber, bräche es in der ersten Zeile ab, bevor die Tabelle fällt, die
 * Zusatzfelder verschwinden und die privaten Kundenuploads gelöscht sind. Ein Betreiber, der das
 * Plugin auf ein Löschersuchen hin entfernt, hielte dann für aufgeräumt, was noch da ist.
 */
final class RcOrderAttachmentLifecycleTest extends TestCase
{
    /**
     * Der private Alias im Quelltext fiele sonst erst bei der nächsten echten Deinstallation
     * auf, also zu spät. Der Test liest deshalb die Quelldatei selbst.
     */
    public function testUninstallUsesThePublicConnectionService(): void
    {
        $source = file_get_contents(\dirname(__DIR__, 2) . '/src/RcOrderAttachment.php');
        self::assertIsString($source);

        self::assertStringNotContainsString(
            "'database_connection'",
            $source,
            'der Alias ist privat — get() wirft darauf und bricht den Uninstall ab',
        );
        self::assertStringContainsString('Connection::class', $source);
    }

    /**
     * Der Kern der Klasse: Würfe eine unbekannte ID durch, endete die ganze Deinstallation, und
     * die privaten Uploads blieben liegen. Der Lebenszyklus läuft deshalb weiter und protokolliert
     * die gesuchte ID.
     */
    public function testMissingServiceIsLoggedInsteadOfThrown(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logEntries = [];
        $logger->method('error')->willReturnCallback(
            static function (string|Stringable $message, array $context = []) use (&$logEntries): void {
                $logEntries[] = ['message' => (string) $message, 'context' => $context];
            },
        );

        $container = $this->containerThatOnlyKnowsLogger($logger);

        $plugin = new RcOrderAttachment(true, __DIR__);
        $result = $this->callOptionalService($plugin, $container, 'gibt.es.nicht');

        self::assertNull($result, 'ein fehlender Service liefert null statt zu werfen');
        self::assertCount(1, $logEntries);
        self::assertSame('rc_order_attachment.lifecycle.service_missing', $logEntries[0]['message']);
        self::assertSame('gibt.es.nicht', $logEntries[0]['context']['serviceId']);
    }

    /**
     * Das Aufräumen darf nicht ausgerechnet daran scheitern, dass der Logger fehlt. Sonst
     * tauschte man einen stillen Fehler gegen einen lauten Abbruch.
     */
    public function testWorksWithoutAnyLogger(): void
    {
        $container = $this->containerThatKnowsNothing();

        $plugin = new RcOrderAttachment(true, __DIR__);

        self::assertNull($this->callOptionalService($plugin, $container, 'auch.nicht.da'));
    }

    /**
     * Vorgemerkte und verwaiste Uploads haben keine Zeile in `rc_order_attachment`, nur den Ordner.
     * Die Deinstallation löscht sie mit; doppelte Kennungen erscheinen einmal.
     */
    public function testUninstallAlsoDeletesTheUploadsInThePluginFolder(): void
    {
        $linked = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $pending = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchFirstColumn')->willReturn([$pending, strtoupper($linked)]);

        $method = new ReflectionMethod(RcOrderAttachment::class, 'mediaIdsToDelete');
        $ids = $method->invoke(new RcOrderAttachment(true, __DIR__), $connection, [$linked], 'cccccccccccccccccccccccccccccccc');

        self::assertSame([$linked, $pending], $ids);
    }

    public function testWithoutFolderOnlyTheLinkedUploadsAreDeleted(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchFirstColumn');

        $method = new ReflectionMethod(RcOrderAttachment::class, 'mediaIdsToDelete');

        self::assertSame(['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'], $method->invoke(new RcOrderAttachment(true, __DIR__), $connection, ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'], null));
    }

    private function callOptionalService(RcOrderAttachment $plugin, ContainerInterface $container, string $id): ?object
    {
        $method = new ReflectionMethod($plugin, 'optionalService');

        return $method->invoke($plugin, $container, $id);
    }

    /**
     * Der Container kennt den Logger nur unter `monolog.logger.business_events`, nicht unter
     * `logger`, wie in Shopware 6.7. Ein Double, das `logger` liefert, stellte die Liste der
     * Kandidaten nie auf die Probe.
     */
    private function containerThatOnlyKnowsLogger(LoggerInterface $logger): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id) use ($logger): object {
                if ($id === 'monolog.logger.business_events') {
                    return $logger;
                }

                throw new ServiceNotFoundException($id);
            },
        );

        return $container;
    }

    private function containerThatKnowsNothing(): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static function (string $id): object {
                throw new ServiceNotFoundException($id);
            },
        );

        return $container;
    }
}
