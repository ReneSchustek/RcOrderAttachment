<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Image;

use GdImage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Entfernt EXIF- und andere Metadaten aus Bildern, bevor sie gespeichert werden.
 *
 * Smartphones schreiben GPS-Koordinaten, Gerätekennung und Aufnahmezeit in jedes Foto, ohne dass
 * der Kunde davon weiß. Der Shop braucht nichts davon, und die Datenminimierung nach Art. 5 DSGVO
 * verlangt, es nicht aufzubewahren.
 *
 * JPEG, PNG und WebP werden mit GD neu geschrieben, das auf praktisch jedem Shop-Server bereitsteht.
 * HEIC kann GD nicht lesen; dafür bräuchte es Imagick, das nicht überall installiert ist. HEIC-Dateien
 * bleiben deshalb unverändert, und das Protokoll vermerkt es auf Debug-Ebene.
 *
 * Scheitert das Entfernen (kein GD, defekte Datei, Speichermangel), bleibt die Originaldatei
 * stehen und der Upload läuft weiter; das Protokoll hält den Fehler fest.
 */
final class ExifStripper
{
    /**
     * Endungen, die GD lesen und schreiben kann.
     *
     * @var list<string>
     */
    private const GD_SUPPORTED = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Überschreibt die Datei an Ort und Stelle mit einer Fassung ohne Metadaten. Liefert `false`,
     * wenn die Datei unverändert geblieben ist.
     */
    public function stripInPlace(string $path, string $extension): bool
    {
        $extension = strtolower($extension);
        if (!\in_array($extension, self::GD_SUPPORTED, true)) {
            $this->logger->debug('rc_order_attachment.exif.skipped_unsupported', [
                'extension' => $extension,
            ]);

            return false;
        }

        // Fehlt diese Funktion, ist GD gar nicht geladen.
        if (!\function_exists('imagecreatefromjpeg')) {
            $this->logger->warning('rc_order_attachment.exif.gd_not_available');

            return false;
        }

        if (!is_readable($path) || !is_writable($path)) {
            $this->logger->warning('rc_order_attachment.exif.path_not_accessible', ['path' => $path]);

            return false;
        }

        try {
            return $this->stripWithGd($path, $extension);
        } catch (Throwable $exception) {
            $this->logger->warning('rc_order_attachment.exif.strip_failed', [
                'extension' => $extension,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function stripWithGd(string $path, string $extension): bool
    {
        // GD liest EXIF, IPTC, XMP und ICC-Profile gar nicht erst ein, deshalb fehlen sie in der
        // neu geschriebenen Datei. Das gilt auch für die EXIF-Ausrichtung: Ein Hochkantfoto steht
        // oft nur über dieses Kennzeichen aufrecht. Sie wird deshalb vorher gelesen und ins Bild
        // selbst übernommen.
        $orientation = $this->orientation($path, $extension);
        $image = $this->decode($path, $extension);

        if ($image !== false) {
            $image = $this->applyOrientation($image, $orientation);
        }

        if ($image === false) {
            $this->logger->info('rc_order_attachment.exif.image_decode_failed', [
                'extension' => $extension,
            ]);

            return false;
        }

        try {
            $success = $this->encode($image, $path, $extension);

            if (!$success) {
                $this->logger->warning('rc_order_attachment.exif.image_encode_failed', [
                    'extension' => $extension,
                ]);

                return false;
            }

            $this->logger->debug('rc_order_attachment.exif.stripped', [
                'extension' => $extension,
            ]);

            return true;
        } finally {
            // `imagedestroy()` bewirkt unter PHP 8 nichts, weil GdImage ein gewöhnliches Objekt
            // ist, und PHP 8.5 markiert es als veraltet. Das `unset` gibt das Bild nur eher
            // frei; am Ende der Methode fiele die Referenz ohnehin weg.
            unset($image);
        }
    }

    /**
     * Die EXIF-Ausrichtung (1 bis 8), 1 für „steht schon richtig".
     *
     * Nur JPEG: In PNG und WebP steht sie selten, und `exif_read_data` liest dort nichts. Fehlt die
     * exif-Erweiterung, bleibt es bei 1; das Bild wird dann ungedreht geschrieben.
     */
    private function orientation(string $path, string $extension): int
    {
        if (!\in_array($extension, ['jpg', 'jpeg'], true) || !\function_exists('exif_read_data')) {
            return 1;
        }

        $exif = $this->runGd(static fn (): array|false => exif_read_data($path));
        $value = \is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        return \is_int($value) && $value >= 1 && $value <= 8 ? $value : 1;
    }

    /**
     * Dreht und spiegelt das Bild so, wie ein Betrachter es nach der Ausrichtung zeigt.
     * `imagerotate` dreht gegen den Uhrzeigersinn; -90 ist also eine Vierteldrehung im Uhrzeigersinn.
     */
    private function applyOrientation(GdImage $image, int $orientation): GdImage
    {
        if (\in_array($orientation, [2, 7], true)) {
            imageflip($image, \IMG_FLIP_HORIZONTAL);
        } elseif (\in_array($orientation, [4, 5], true)) {
            imageflip($image, \IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 6, 7 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    /**
     * Dekodiert die Datei nach GD. Liefert `false`, wenn GD den Inhalt nicht
     * lesen kann (defekte oder getarnte Datei).
     */
    private function decode(string $path, string $extension): GdImage|false
    {
        $warning = null;
        $result = $this->runGd(
            static fn (): GdImage|false => match ($extension) {
                'jpg', 'jpeg' => imagecreatefromjpeg($path),
                'png' => imagecreatefrompng($path),
                'webp' => \function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
                default => false,
            },
            $warning,
        );

        // Über den generischen Rückgabetyp von runGd() wird aus `GdImage|false` für die statische
        // Analyse `GdImage|bool`; die Zeile stellt den engen Typ wieder her.
        $image = $result instanceof GdImage ? $result : false;

        if ($image === false && $warning !== null) {
            $this->logger->debug('rc_order_attachment.exif.gd_decode_warning', [
                'extension' => $extension,
                'warning' => $warning,
            ]);
        }

        return $image;
    }

    /**
     * Schreibt das Bild ohne Metadaten zurück an dieselbe Stelle.
     *
     * Das Bild geht zuerst in eine temporäre Datei und ersetzt das Original erst bei Erfolg per
     * `rename()`. Bräche das Schreiben mittendrin ab, etwa bei voller Platte, bliebe sonst eine
     * verstümmelte Datei zurück, und das Medium wäre beschädigt.
     */
    private function encode(GdImage $image, string $path, string $extension): bool
    {
        $tmpPath = $path . '.rcstrip';

        $warning = null;
        $success = $this->runGd(
            function () use ($image, $tmpPath, $extension): bool {
                return match ($extension) {
                    // Qualität 90 hält Schrift und feine Linien auf Fotos von Zeichnungen lesbar;
                    // darunter treten Kompressionsspuren an Kanten sichtbar hervor, darüber wächst
                    // die Datei stark, ohne dass man den Unterschied sieht.
                    'jpg', 'jpeg' => imagejpeg($image, $tmpPath, 90),
                    'png' => $this->writePngWithAlpha($image, $tmpPath),
                    'webp' => \function_exists('imagewebp') && imagewebp($image, $tmpPath, 90),
                    default => false,
                };
            },
            $warning,
        );

        if (!$success) {
            $this->cleanupTemp($tmpPath);
            if ($warning !== null) {
                $this->logger->debug('rc_order_attachment.exif.gd_encode_warning', [
                    'extension' => $extension,
                    'warning' => $warning,
                ]);
            }

            return false;
        }

        $renameWarning = null;
        $renamed = $this->runGd(static fn (): bool => rename($tmpPath, $path), $renameWarning);
        if ($renamed !== true) {
            $this->cleanupTemp($tmpPath);
            $this->logger->warning('rc_order_attachment.exif.rename_failed', [
                'extension' => $extension,
                'warning' => $renameWarning,
            ]);

            return false;
        }

        return true;
    }

    private function cleanupTemp(string $tmpPath): void
    {
        if (!is_file($tmpPath)) {
            return;
        }

        $this->runGd(static fn (): bool => unlink($tmpPath));
    }

    private function writePngWithAlpha(GdImage $image, string $path): bool
    {
        // Ohne diese beiden Aufrufe ginge die Transparenz verloren. Text- und EXIF-Blöcke
        // schreibt GD nicht mit.
        imagealphablending($image, false);
        imagesavealpha($image, true);

        // Stufe 6 ist der zlib-Standard. PNG bleibt bei jeder Stufe verlustfrei; höhere Stufen
        // sparen wenig Platz und kosten deutlich mehr Rechenzeit.
        return imagepng($image, $path, 6);
    }

    /**
     * Führt einen GD-Aufruf aus und fängt dessen `E_WARNING` ab, statt sie mit `@` zu
     * unterdrücken, das auch die Ursache verschwinden ließe.
     *
     * GD meldet jeden Fehler doppelt: als Rückgabewert `false`, den der Aufrufer ohnehin
     * auswertet, und als Warnung. Der Handler liegt nur um den einen Aufruf, reicht die Meldung
     * fürs Protokoll heraus und wird im `finally` wieder abgemeldet. Anders als `@` verschluckt er
     * keine fremden Warnungen und ändert `error_reporting` nicht.
     *
     * @template TResult
     *
     * @param callable(): TResult $operation
     * @param-out string|null     $warning
     *
     * @return TResult
     */
    private function runGd(callable $operation, ?string &$warning = null): mixed
    {
        $captured = null;

        set_error_handler(static function (int $severity, string $message) use (&$captured): bool {
            $captured = $message;

            // `true` meldet die Warnung als behandelt, sie erreicht den globalen Handler nicht.
            return true;
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
            $warning = $captured;
        }
    }
}
