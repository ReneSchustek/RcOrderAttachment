<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Image;

use GdImage;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Entfernt EXIF-/Metadaten aus Bilddateien VOR der Speicherung.
 *
 * Hintergrund (DSGVO):
 *   Smartphone-Kameras schreiben standardmäßig GPS-Koordinaten, Geräte-ID
 *   und Aufnahmezeitpunkt in jedes Foto. Der Customer ist sich dessen meist
 *   nicht bewusst — bei B2C-Bestellungen ein klarer Informationsverlust
 *   nach DSGVO Art. 5 (Datenminimierung).
 *
 * Strategie:
 *   - JPEG/PNG/WebP: re-encoden mit GD (verbreitet auf jedem Shop-Host)
 *   - HEIC: GD kann das nicht; Imagick wäre nötig, ist aber nicht überall
 *     verfügbar. Bei HEIC liefern wir das Original durch und loggen einen
 *     Hinweis. Mitarbeiter-Mail-Client zeigt das Foto trotzdem an.
 *
 * Fail-Safe-Verhalten:
 *   Wenn das Strippen aus irgendeinem Grund fehlschlägt (keine GD-Extension,
 *   defekte Datei, OOM), wird der Originalpfad zurückgegeben — der Upload-Pfad
 *   bricht NICHT ab. Logging dokumentiert das Versagen.
 */
final class ExifStripper
{
    /**
     * Endungen, die wir mit GD bearbeiten können.
     *
     * @var list<string>
     */
    private const GD_SUPPORTED = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Strippt EXIF/Metadaten aus der Datei und überschreibt sie in-place.
     * Gibt zurück, ob tatsächlich gestrippt wurde (für Logging/Tests).
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
        // Erst Bild laden, dann mit Alpha-Erhalt re-encoden. GD verwirft
        // EXIF/IPTC/XMP/ICC-Profile automatisch beim Re-Encode, weil es sie
        // gar nicht erst liest.
        $image = $this->decode($path, $extension);

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
            // `imagedestroy()` ist seit PHP 8.0 wirkungslos (GdImage ist ein
            // Objekt mit normalem GC) und seit PHP 8.5 deprecated. `unset`
            // bleibt nur der Vollständigkeit halber — der Refcount fällt
            // sowieso am Ende des try/finally-Blocks.
            unset($image);
        }
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

        // `GdImage|false` kollabiert über den generischen runGd()-Rückgabetyp zu
        // `GdImage|bool` — hier wieder auf den engen Vertrag verschmälern.
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
     * Atomar: zuerst in eine temporäre Datei schreiben, erst bei Erfolg per
     * `rename()` über das Original ziehen. Ein Schreibfehler MITTEN im GD-Encode
     * (z. B. Disk voll) würde sonst die Original-Upload-Datei verstümmelt
     * zurücklassen — und ein korruptes Media speichern.
     */
    private function encode(GdImage $image, string $path, string $extension): bool
    {
        $tmpPath = $path . '.rcstrip';

        $warning = null;
        $success = $this->runGd(
            function () use ($image, $tmpPath, $extension): bool {
                return match ($extension) {
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
        // PNG-Transparenz erhalten, EXIF/Text-Chunks fliegen weg.
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return imagepng($image, $path, 6);
    }

    /**
     * Führt einen GD-Aufruf aus und fängt die von GD emittierte `E_WARNING`
     * ab, statt sie mit dem `@`-Operator zu unterdrücken — unterdrückte Fehler
     * verstecken die Ursache.
     *
     * GD meldet jeden Fehler doppelt: einmal als Rückgabewert `false` — den wir
     * ohnehin auswerten — und einmal als Warning. Der Handler ist eng um den
     * einzelnen Aufruf gelegt, gibt die Meldung zum Logging heraus und wird im
     * `finally` garantiert zurückgenommen. Anders als `@` verschluckt er weder
     * fremde Warnings noch schaltet er `error_reporting` global ab.
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

            // true = Warning ist behandelt, kein Bubbling in den globalen Handler.
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
