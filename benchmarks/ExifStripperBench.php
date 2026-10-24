<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Benchmarks;

use Generator;
use PhpBench\Attributes as Bench;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Service\Image\ExifStripper;
use RuntimeException;

/**
 * Das Entfernen der Metadaten aus hochgeladenen Fotos, je Bildgröße.
 *
 * Der Stripper dekodiert jedes Foto vollständig mit GD und speichert es neu. Zeit und Spitzenspeicher
 * steigen mit der Pixelzahl; reicht der Speicher nicht, gibt der Stripper das Original zurück, und die
 * Metadaten samt Standort bleiben im Foto. Die gemessenen Werte gehören deshalb neben die
 * Speichergrenze von PHP auf dem Server.
 *
 * Die Testfotos erzeugt `make-images.php` vorab, damit ihr Aufbau nicht in den Spitzenspeicher fällt.
 * Drei Wiederholungen in fünf Durchläufen halten die Streuung unter fünf Prozent (siehe results.csv),
 * der Aufwärmlauf hält das erste Laden von GD aus der Messung.
 */
#[Bench\Revs(3)]
#[Bench\Iterations(5)]
#[Bench\Warmup(1)]
final class ExifStripperBench
{
    private ExifStripper $stripper;

    private string $work;

    public function __construct()
    {
        $this->stripper = new ExifStripper(new NullLogger());
    }

    /**
     * @return Generator<string, array{file: string}>
     */
    public function sizes(): Generator
    {
        yield '12 Megapixel' => ['file' => '12mp.jpg'];
        yield '24 Megapixel' => ['file' => '24mp.jpg'];
        yield '48 Megapixel' => ['file' => '48mp.jpg'];
    }

    /**
     * Eine Kopie je Variante: Der Stripper überschreibt die Datei, und das Original soll für den
     * nächsten Lauf unverändert bleiben.
     *
     * @param array{file: string} $params
     */
    public function copyImage(array $params): void
    {
        $directory = getenv('RC_BENCH_IMAGES');
        if (!\is_string($directory) || $directory === '') {
            $directory = sys_get_temp_dir() . '/rc-bench-images';
        }
        $source = $directory . '/' . $params['file'];
        if (!is_file($source)) {
            throw new RuntimeException("Testfoto {$source} fehlt; zuerst make-images.php laufen lassen.");
        }

        $this->work = sys_get_temp_dir() . '/rc-bench-strip-' . $params['file'];
        copy($source, $this->work);

        if (!$this->stripper->stripInPlace($this->work, 'jpg')) {
            throw new RuntimeException("Der Stripper hat {$params['file']} nicht bearbeitet; gemessen würde der Rückfall.");
        }
    }

    /**
     * @param array{file: string} $params
     */
    #[Bench\BeforeMethods('copyImage')]
    #[Bench\ParamProviders('sizes')]
    public function benchStrip(array $params): void
    {
        $this->stripper->stripInPlace($this->work, 'jpg');
    }

    /**
     * Ein Hochkantfoto mit EXIF-Ausrichtung 6: Der Stripper dreht es. Eine Wiederholung je Kopie und
     * kein Aufwärmlauf, denn nach dem ersten Durchgang fehlt die Ausrichtung, und jeder weitere drehte
     * nichts mehr; gemessen wären dann rund 600 statt rund 1200 ms.
     */
    #[Bench\BeforeMethods('copyPortrait')]
    #[Bench\Revs(1)]
    #[Bench\Warmup(0)]
    public function benchStripPortrait(): void
    {
        $this->stripper->stripInPlace($this->work, 'jpg');
    }

    /**
     * Die Kopie ohne den Kontrolldurchgang von `copyImage()`: Der entfernte die Ausrichtung, und
     * gemessen würde ein Foto, das nichts mehr zu drehen hat. Geprüft wird stattdessen, dass sie da ist.
     */
    public function copyPortrait(): void
    {
        $directory = getenv('RC_BENCH_IMAGES');
        if (!\is_string($directory) || $directory === '') {
            $directory = sys_get_temp_dir() . '/rc-bench-images';
        }

        $this->work = sys_get_temp_dir() . '/rc-bench-strip-48mp-hochkant.jpg';
        copy($directory . '/48mp-hochkant.jpg', $this->work);

        $exif = @exif_read_data($this->work);
        if (!\is_array($exif) || ($exif['Orientation'] ?? null) !== 6) {
            throw new RuntimeException('Dem Hochkantfoto fehlt die Ausrichtung 6; zuerst make-images.php laufen lassen.');
        }
    }
}
