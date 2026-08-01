<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Ruhrcoder\RcOrderAttachment\RcOrderAttachment;
use Stringable;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * Sichert die Container-Zugriffe des Plugin-Lifecycles.
 *
 * Hintergrund: `uninstall()` holte die Datenbank-Verbindung über den Symfony-Alias
 * `database_connection`. Der ist **privat**, `get()` wirft darauf — und zwar in der ersten
 * Zeile, also bevor die Tabelle fällt, bevor die Custom-Fields verschwinden und bevor die
 * privaten Customer-Uploads gelöscht werden. Ein Betreiber, der das Plugin auf ein
 * Löschersuchen hin entfernt, glaubte aufgeräumt zu haben und hatte es nicht.
 *
 * Zwei Dinge werden hier festgenagelt: die richtige Service-ID, und dass ein fehlschlagender
 * Container-Zugriff geloggt statt durchgereicht wird.
 */
final class RcOrderAttachmentLifecycleTest extends TestCase
{
    /**
     * Was: Die Quelle darf den privaten Alias nicht mehr verwenden.
     * Warum: Er ist der Grund, aus dem die Deinstallation abbrach. Eine Rückkehr wäre still —
     *        sie fällt erst bei der nächsten echten Deinstallation auf, also zu spät.
     * Erwartet: Kein `'database_connection'` mehr im Bootstrapper, dafür `Connection::class`.
     */
    public function testUninstallUsesThePublicConnectionService(): void
    {
        $quelle = file_get_contents(\dirname(__DIR__, 2) . '/src/RcOrderAttachment.php');
        self::assertIsString($quelle);

        self::assertStringNotContainsString(
            "'database_connection'",
            $quelle,
            'der Alias ist privat — get() wirft darauf und bricht den Uninstall ab',
        );
        self::assertStringContainsString('Connection::class', $quelle);
    }

    /**
     * Was: Ein Container, der auf jede Anfrage wirft.
     * Warum: KERN-NACHWEIS. Vorher reichte eine unbekannte ID, um den gesamten Uninstall zu
     *        beenden — mit zurückbleibenden privaten Uploads. Der Lifecycle muss stattdessen
     *        weiterlaufen und den Fehlgriff protokollieren.
     * Erwartet: Keine Exception nach aussen, ein Log-Eintrag mit der gesuchten Service-ID.
     */
    public function testMissingServiceIsLoggedInsteadOfThrown(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $protokoll = [];
        $logger->method('error')->willReturnCallback(
            static function (string|Stringable $message, array $context = []) use (&$protokoll): void {
                $protokoll[] = ['message' => (string) $message, 'context' => $context];
            },
        );

        $container = $this->containerThatOnlyKnowsLogger($logger);

        $plugin = new RcOrderAttachment(true, __DIR__);
        $ergebnis = $this->rufeOptionalService($plugin, $container, 'gibt.es.nicht');

        self::assertNull($ergebnis, 'ein fehlender Service liefert null statt zu werfen');
        self::assertCount(1, $protokoll);
        self::assertSame('rc_order_attachment.lifecycle.service_missing', $protokoll[0]['message']);
        self::assertSame('gibt.es.nicht', $protokoll[0]['context']['serviceId']);
    }

    /**
     * Was: Der Logger selbst ist nicht erreichbar.
     * Warum: Der Aufräum-Weg darf nicht ausgerechnet daran scheitern, dass das Protokollieren
     *        scheitert — sonst tauscht man einen stillen Fehler gegen einen lauten Abbruch.
     * Erwartet: Kein Fehler, Rückgabe null.
     */
    public function testWorksWithoutAnyLogger(): void
    {
        $container = $this->containerThatKnowsNothing();

        $plugin = new RcOrderAttachment(true, __DIR__);

        self::assertNull($this->rufeOptionalService($plugin, $container, 'auch.nicht.da'));
    }

    private function rufeOptionalService(RcOrderAttachment $plugin, ContainerInterface $container, string $id): ?object
    {
        $methode = new ReflectionMethod($plugin, 'optionalService');

        return $methode->invoke($plugin, $container, $id);
    }

    /**
     * Container, der den Logger **nicht** unter `logger` kennt, sondern nur unter der
     * Business-Events-ID — genau die Lage in Shopware 6.7. Ein Double, das `logger` liefert,
     * würde die Kandidatenliste nie auf die Probe stellen.
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
