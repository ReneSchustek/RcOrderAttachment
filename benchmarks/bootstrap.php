<?php

declare(strict_types=1);

/*
 * Bootstrap der Messungen. Lädt den Autoloader der Shopware-Installation, in der die Erweiterung
 * liegt, und meldet die Namensräume der Erweiterung und der Messungen an. Einen Kern braucht keine
 * der Messungen: Der EXIF-Stripper hängt nur an GD und einem Logger.
 */

$projectRoot = getenv('PROJECT_ROOT');
if (!\is_string($projectRoot) || $projectRoot === '') {
    $projectRoot = dirname(__DIR__, 4);
}
if (!is_file($projectRoot . '/vendor/autoload.php')) {
    throw new RuntimeException("Keine Shopware-Installation unter {$projectRoot}; PROJECT_ROOT setzen.");
}

$classLoader = require $projectRoot . '/vendor/autoload.php';
$classLoader->addPsr4('Ruhrcoder\\RcOrderAttachment\\Benchmarks\\', __DIR__);
$classLoader->addPsr4('Ruhrcoder\\RcOrderAttachment\\', dirname(__DIR__) . '/src');
