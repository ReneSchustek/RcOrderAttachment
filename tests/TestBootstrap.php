<?php

declare(strict_types=1);

/*
 * Lädt zuerst den Autoloader aus dem eigenen `vendor/` (Plugin ohne Shop), dann den der
 * umgebenden Shopware-Installation vier Ebenen höher. Der eigene PSR-4-Loader für
 * `Ruhrcoder\\RcOrderAttachment\\` ist immer registriert und greift, wenn keiner der beiden
 * die Klasse kennt.
 *
 * So laufen die Unit-Tests lokal mit `vendor/bin/phpunit` ebenso wie auf dem Server im
 * Shopware-Testkontext.
 */

$pluginAutoloader = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($pluginAutoloader)) {
    require_once $pluginAutoloader;
}

$shopwareAutoloader = dirname(__DIR__, 4) . '/vendor/autoload.php';
if (file_exists($shopwareAutoloader)) {
    require_once $shopwareAutoloader;
}

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Ruhrcoder\\RcOrderAttachment\\Tests\\' => __DIR__ . '/',
        'Ruhrcoder\\RcOrderAttachment\\' => dirname(__DIR__) . '/src/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        $length = strlen($prefix);
        if (strncmp($class, $prefix, $length) !== 0) {
            continue;
        }
        $file = $baseDir . str_replace('\\', '/', substr($class, $length)) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

/*
 * Der Kernel darf nur booten, wenn das Plugin in einer Shopware-Installation liegt.
 *
 * `class_exists(TestBootstrapper::class)` taugt als Prüfung nicht, weil `shopware/core` eine
 * `require`-Abhängigkeit ist und die Klasse auch im eigenständigen Checkout existiert (CI,
 * frischer `composer install`). Der Bootstrap versuchte dort einen Shop zu booten, den es nicht
 * gibt, und bräche mit „Could not find plugin: RcOrderAttachment" ab, bevor ein einziger
 * Unit-Test läuft.
 *
 * Gesucht wird zuerst im Aufrufverzeichnis, weil Integrationstests aus dem Wurzelverzeichnis
 * des Shops gestartet werden (`vendor/bin/phpunit -c custom/plugins/…`). Danach vier Ebenen
 * über `tests/`; das trifft `custom/plugins/<Plugin>/tests/`, solange der Pfad kein Symlink ist.
 */
$shopwareRoot = null;
foreach ([getcwd(), \dirname(__DIR__, 4)] as $candidate) {
    // `getcwd()` kann false liefern, deshalb die Typprüfung. Einen leeren Text liefert es nie,
    // ein Vergleich darauf entfällt.
    if (\is_string($candidate) && is_file($candidate . '/config/bundles.php')) {
        $shopwareRoot = $candidate;
        break;
    }
}

// `KernelTestBehaviour` und `IntegrationTestBehaviour` erwarten, dass der Kernel vor dem
// ersten Test vorbereitet ist. Ohne Shopware-Installation entfällt das, die Unit-Tests
// brauchen dann nur den Composer-Autoloader.
if ($shopwareRoot !== null && class_exists(\Shopware\Core\TestBootstrapper::class)) {
    // Die DDEV-Installation setzt in `/var/www/html/.env.test` `KERNEL_CLASS=App\Kernel`, eine
    // Klasse, die es dort nicht gibt. Ist der Wert leer oder nicht ladbar (auch wenn die
    // Kommandozeile den Backslash verschluckt), gilt `Shopware\Core\Kernel`, die Vorgabe von
    // `KernelFactory::$kernelClass`. Der Wert steht fest, bevor Dotenv lädt, das ohne
    // `override` nichts schon Gesetztes überschreibt.
    $kernelClassFromEnv = getenv('KERNEL_CLASS');
    $currentKernelClass = $kernelClassFromEnv !== false && $kernelClassFromEnv !== ''
        ? $kernelClassFromEnv
        : ($_SERVER['KERNEL_CLASS'] ?? '');
    if ($currentKernelClass === '' || !class_exists($currentKernelClass)) {
        putenv('KERNEL_CLASS=Shopware\\Core\\Kernel');
        $_SERVER['KERNEL_CLASS'] = 'Shopware\\Core\\Kernel';
        $_ENV['KERNEL_CLASS'] = 'Shopware\\Core\\Kernel';
    }

    $bootstrapper = (new \Shopware\Core\TestBootstrapper())
        ->setPlatformEmbedded(false)
        // Beim ersten Bootstrap installiert `TestBootstrapper` das aufrufende Plugin selbst
        // (`bootstrap()` ruft `installPlugins()`), danach ist es aktiv. `setForceInstallPlugins(true)`
        // fehlt absichtlich: Es löst Deinstallation und Installation aus, und `uninstall()` des
        // Plugins braucht `database_connection` (Namenskonflikt im Testkernel) und ist nicht
        // wiederholbar.
        ->addCallingPlugin();

    // `setProjectDir()` allein reicht nicht, weil `KernelFactory::getProjectDir()` zuerst
    // `$_SERVER['PROJECT_ROOT']` liest. Fehlt die Variable, leitet es den Pfad per Reflection
    // aus dem Ort der Klasse ab, und bei einem per Composer installierten `vendor/` des Plugins
    // zeigt der ins Plugin statt in die Shopware-Installation.
    $bootstrapper->setProjectDir($shopwareRoot);
    $_SERVER['PROJECT_ROOT'] = $shopwareRoot;
    $_ENV['PROJECT_ROOT'] = $shopwareRoot;
    putenv('PROJECT_ROOT=' . $shopwareRoot);

    $bootstrapper->bootstrap();
}
