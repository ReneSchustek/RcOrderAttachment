<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Vergleicht die Dateigröße mit `maxFileSizeKb`. Die Grenzen von PHP (`upload_max_filesize`,
 * `post_max_size`) gelten zusätzlich und greifen schon vorher.
 *
 * Liefert `getSize()` den Wert `false`, ist der Upload meist abgebrochen. Er bekommt denselben
 * Code wie eine leere Datei (`EMPTY_UPLOAD`), damit der Kunde eine verständliche Meldung sieht.
 */
final class FileSizeValidator
{
    public function validate(UploadedFile $file, PluginConfig $config, ValidationResult $result): void
    {
        $size = $file->getSize();
        if ($size === false || $size <= 0) {
            $result->fail(ValidationCode::EMPTY_UPLOAD);

            return;
        }

        $max = $config->maxFileSizeBytes();
        if ($size > $max) {
            $result->fail(ValidationCode::FILE_SIZE_EXCEEDED, [
                'sizeKb' => (int) ceil($size / 1024),
                'maxKb' => $config->maxFileSizeKb,
            ]);
        }
    }
}
