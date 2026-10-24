<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Führt die einzelnen Prüfer für einen Upload nacheinander aus.
 *
 * Nur zwei Prüfungen brechen sofort ab: ein abgeschaltetes Plugin und ein Upload, den PHP schon
 * als fehlerhaft gemeldet hat, weil es dann keine Datei zu prüfen gibt. Danach laufen Anzahl,
 * Endung, Sperrliste, MIME-Typ, Signatur, Dateigröße und Gesamtgröße alle durch, auch nach einem
 * Befund. So sieht der Kunde alle Probleme einer Datei mit einer einzigen Antwort.
 */
final class UploadValidator
{
    public function __construct(
        private readonly ExtensionValidator $extensionValidator,
        private readonly DangerousContentValidator $dangerousContentValidator,
        private readonly MimeTypeValidator $mimeTypeValidator,
        private readonly MagicBytesValidator $magicBytesValidator,
        private readonly FileSizeValidator $fileSizeValidator,
        private readonly TotalSizeValidator $totalSizeValidator,
        private readonly FileCountValidator $fileCountValidator,
    ) {
    }

    public function validate(
        UploadedFile $file,
        PluginConfig $config,
        int $alreadyUploadedCount,
        int $alreadyUploadedBytes,
    ): ValidationResult {
        $result = new ValidationResult();

        if (!$config->enabled) {
            $result->fail(ValidationCode::PLUGIN_DISABLED);

            return $result;
        }

        if (!$file->isValid()) {
            $result->fail(ValidationCode::UPLOAD_ERROR, [
                'reason' => $file->getErrorMessage(),
            ]);

            return $result;
        }

        $this->fileCountValidator->validate($alreadyUploadedCount, $config, $result);
        $this->extensionValidator->validate($file, $config, $result);
        $this->dangerousContentValidator->validate($file, $result);
        $this->mimeTypeValidator->validate($file, $result);
        $this->magicBytesValidator->validate($file, $result);
        $this->fileSizeValidator->validate($file, $config, $result);
        $this->totalSizeValidator->validate($file, $alreadyUploadedBytes, $config, $result);

        return $result;
    }
}
