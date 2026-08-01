<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;

/**
 * Erzwingt die Maximalzahl an Anhängen pro Bestellung. Der Zähler kommt aus
 * der Session und ist `>=`-vergleich, damit der gerade hochzuladende Slot
 * (= +1) noch in den Quote-Vergleich eingeht.
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
