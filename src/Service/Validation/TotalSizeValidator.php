<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Begrenzt die Summe aus schon hochgeladenen Dateien und der neuen.
 *
 * Ohne diese Grenze könnte ein Kunde viele Dateien knapp unter `maxFileSizeKb` hochladen und so
 * weit mehr Speicher belegen als vorgesehen. Verglichen wird die Summe einschließlich der neuen
 * Datei, weil die Grenze für die ganze Bestellung gilt.
 */
final class TotalSizeValidator
{
    public function validate(
        UploadedFile $file,
        int $alreadyUploadedBytes,
        PluginConfig $config,
        ValidationResult $result,
    ): void {
        $size = $file->getSize();
        // Eine leere oder abgebrochene Datei meldet schon der FileSizeValidator.
        if ($size === false || $size <= 0) {
            return;
        }

        $projected = $alreadyUploadedBytes + $size;
        $max = $config->maxTotalSizeBytes();
        if ($projected > $max) {
            $result->fail(ValidationCode::TOTAL_SIZE_EXCEEDED, [
                'projectedKb' => (int) ceil($projected / 1024),
                'maxKb' => $config->maxTotalSizeKb,
            ]);
        }
    }
}
