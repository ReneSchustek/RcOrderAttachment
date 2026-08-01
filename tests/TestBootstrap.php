<?php

declare(strict_types=1);

/*
 * Test-Bootstrap. Erst lokales `vendor/`-Autoloader (Plugin-Standalone),
 * dann der Shopware-Core-Autoloader von einem über das Plugin gelagerten
 * Composer-Setup (`../../..`). Falls beides fehlt, springt der eigene
 * PSR-4-Loader für `Ruhrcoder\\RcOrderAttachment\\` als Fallback ein.
 *
 * Damit laufen die Unit-Tests sowohl lokal mit `vendor/bin/phpunit` als
 * auch auf dem Server im Shopware-Test-Kontext.
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
 * Shopware-Root suchen. Der Kernel-Bootstrap darf NUR laufen, wenn das Plugin
 * tatsächlich innerhalb einer Shopware-Installation getestet wird.
 *
 * Nicht auf `class_exists(TestBootstrapper::class)` prüfen: `shopware/core` ist
 * eine `require`-Abhängigkeit, die Klasse existiert also auch im Standalone-
 * Checkout (CI, frischer `composer install`). Der Bootstrap versucht dann einen
 * Shop zu booten, den es dort nicht gibt, und stirbt mit
 * „Could not find plugin: RcOrderAttachment" — noch bevor ein einziger
 * Unit-Test läuft.
 *
 * Kandidaten in dieser Reihenfolge:
 *   1. Aufruf-Verzeichnis — Konvention: Integration-Tests werden aus dem
 *      Shopware-Root gestartet (`vendor/bin/phpunit -c custom/plugins/…`).
 *   2. Vier Ebenen über `tests/` — greift bei `custom/plugins/<Plugin>/tests/`,
 *      solange der Pfad kein Symlink ist.
 */
$shopwareRoot = null;
foreach ([getcwd(), \dirname(__DIR__, 4)] as $candidate) {
    if (\is_string($candidate) && $candidate !== '' && is_file($candidate . '/config/bundles.php')) {
        $shopwareRoot = $candidate;
        break;
    }
}

// Kernel-Lifecycle vorbereiten. KernelTestBehaviour/IntegrationTestBehaviour
// erwarten, dass `KernelLifecycleManager::prepare($classLoader)` aufgerufen
// wurde, bevor der erste Test läuft. Im Standalone-Unit-Lauf bleibt das ein
// No-op — die Unit-Tests brauchen nur den Composer-Autoloader.
if ($shopwareRoot !== null && class_exists(\Shopware\Core\TestBootstrapper::class)) {
    // KERNEL_CLASS-Pin: DDEV-Shopware-Setup hat in `/var/www/html/.env.test`
    // `KERNEL_CLASS=App\Kernel` stehen — `App\Kernel` existiert in dieser
    // Setup-Variante nicht (Production nutzt `KernelFactory::create()` ohne
    // App-Kernel). `Shopware\Core\Kernel` ist die konkrete Default-Klasse, die
    // `KernelFactory::$kernelClass` zeigt. Wir setzen sie früh, damit Dotenv
    // (override=false) sie nicht überschreibt.
    // Robuster Pin: wenn der KERNEL_CLASS-Wert nicht autoloadable ist (klassischer
    // DDEV-Fall: `.env.test` setzt `App\Kernel`, das nicht existiert; oder
    // CLI-Aufruf hat den Backslash verschluckt), fallback auf `Shopware\Core\Kernel`.
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
        ->addCallingPlugin();  // RcOrderAttachment im Test-Kernel registrieren. Beim ersten Bootstrap installiert TestBootstrapper das Plugin automatisch (siehe `bootstrap()` -> `installPlugins()`); danach ist es aktiv und die services.xml wird geladen. `setForceInstallPlugins(true)` triggert einen uninstall->install-Zyklus, der den Plugin-eigenen uninstall()-Pfad durchläuft — der wiederum `database_connection` braucht (Service-Name-Konflikt im Test-Kernel) und nicht idempotent ist. Daher hier weglassen.

    // ProjectDir explizit auf das gefundene Shopware-Root setzen.
    // Wichtig: `KernelFactory::getProjectDir()` liest `$_SERVER['PROJECT_ROOT']`
    // *vor* dem Reflection-Fallback (der sonst den Pfad der KernelFactory-Klasse
    // selbst nimmt — bei composer-installiertem vendor zeigt das auf den
    // Plugin-Pfad, nicht auf das Shopware-Root). `setProjectDir` auf dem
    // TestBootstrapper allein reicht nicht — die env-Variable ist die Quelle.
    $bootstrapper->setProjectDir($shopwareRoot);
    $_SERVER['PROJECT_ROOT'] = $shopwareRoot;
    $_ENV['PROJECT_ROOT'] = $shopwareRoot;
    putenv('PROJECT_ROOT=' . $shopwareRoot);

    $bootstrapper->bootstrap();
}
