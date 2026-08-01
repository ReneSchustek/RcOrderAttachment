<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Begrenzt die Summe der bereits hochgeladenen + der neuen Datei.
 *
 * Schutz gegen einen DoS-Vektor: Customer könnte sonst dutzende kleine Dateien
 * unter dem `maxFileSizeKb`-Limit hochladen, deren Summe das Plugin-Disk-Limit
 * sprengt. Wir prüfen erst gegen den Projected-Total (bestehende Bytes + Neue),
 * weil das Limit semantisch „pro Bestellung", nicht „pro Datei" gilt.
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
