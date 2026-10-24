<?php

declare(strict_types=1);

/*
 * Der tatsächliche Speicher des Prozesses beim Entfernen der Metadaten, für ein Foto je Aufruf.
 *
 * PHPBench zeigt nur den Speicher, den PHP selbst verwaltet. Ist GD extern eingebunden, liegen die
 * Pixel außerhalb davon: `memory_get_peak_usage()` bleibt bei rund 14 MB, auch wenn der Prozess für
 * ein 48-Megapixel-Foto über 250 MB belegt, und `memory_limit` greift nicht. Gemessen wird deshalb
 * der Höchststand des Prozesses (`ru_maxrss`), jedes Foto in einem eigenen Prozess.
 *
 * Ist GD gebündelt, zählt PHP die Pixel mit; dann kann `memory_limit` das Entfernen abbrechen, und
 * der Stripper gibt das Original mit allen Metadaten zurück. Die Ausgabe nennt deshalb auch die Art
 * der Einbindung und ob das Foto bearbeitet wurde.
 *
 * Aufruf: php strip-memory.php <foto.jpg>
 * Ausgabe: Name;Prozess-MB;bearbeitet;GD-Einbindung
 */

require __DIR__ . '/bootstrap.php';

use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Service\Image\ExifStripper;

$source = $argv[1] ?? '';
if (!is_file($source)) {
    throw new RuntimeException("Foto {$source} fehlt.");
}

$work = sys_get_temp_dir() . '/rc-bench-memory-' . basename($source);
copy($source, $work);

$stripped = (new ExifStripper(new NullLogger()))->stripInPlace($work, 'jpg');
unlink($work);

$usage = getrusage();

printf(
    "%s;%d;%s;%s\n",
    basename($source, '.jpg'),
    \is_array($usage) ? $usage['ru_maxrss'] / 1024 : 0,
    $stripped ? 'ja' : 'nein',
    // Gebündeltes GD nennt sich in seiner Versionsangabe so, etwa „bundled (2.1.0 compatible)".
    str_contains(gd_info()['GD Version'], 'bundled') ? 'gebündelt' : 'extern',
);
