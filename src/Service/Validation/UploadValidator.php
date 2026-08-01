<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Orchestriert die Validierungs-Kette in fester Reihenfolge.
 *
 * Reihenfolge folgt einem „cheap first / dangerous first"-Prinzip:
 *
 * 1. Plugin aktiv?               (Konfig-Frage, kein I/O)
 * 2. Upload-Fehler vom Browser?  (HTTP-Layer)
 * 3. Datei nicht leer?           (HTTP-Layer)
 * 4. Anzahl-Limit überschritten? (Session-Counter)
 * 5. Endung erlaubt?             (Whitelist — billigster Reject)
 * 6. Endung gefährlich?          (Blacklist — letztes Wort, überstimmt Whitelist)
 * 7. MIME-Typ stimmt mit Endung? (finfo, ein I/O-Read)
 * 8. Magic-Bytes stimmen?        (eigener I/O-Read auf head)
 * 9. Datei-Größe?                (kein I/O nötig — UploadedFile kennt size)
 * 10. Gesamt-Größe?              (Summe + Session-Counter)
 *
 * Die Kette wird NICHT bei erstem Fehler abgebrochen — der Customer soll alle
 * Probleme einer Datei in einem Roundtrip sehen.
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
