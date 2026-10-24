<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;

/**
 * Begrenzt die Zahl der Anhänge je Bestellung. Der Zähler kommt aus der Sitzung und enthält die
 * neue Datei noch nicht; deshalb lehnt schon `>=` ab.
 */
final class FileCountValidator
{
    public function validate(int $alreadyUploadedCount, PluginConfig $config, ValidationResult $result): void
    {
        if ($alreadyUploadedCount >= $config->maxFiles) {
            $result->fail(ValidationCode::FILE_COUNT_EXCEEDED, [
                'current' => $alreadyUploadedCount,
                'max' => $config->maxFiles,
            ]);
        }
    }
}
