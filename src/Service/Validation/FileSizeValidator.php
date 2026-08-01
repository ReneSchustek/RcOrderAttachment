<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Vergleicht die Datei-Größe gegen das konfigurierte `maxFileSizeKb`. PHP-Limits
 * (`upload_max_filesize`, `post_max_size`) gelten zusätzlich und werden vorher
 * vom Webserver/PHP enforced — dieser Validator deckt das Plugin-Limit ab.
 *
 * `$file->getSize() === false` deutet auf einen abgebrochenen Upload — derselbe
 * Reject wie bei leerer Datei (`EMPTY_UPLOAD`), damit der Customer eine klare
 * Fehlermeldung statt einer technischen Diagnose bekommt.
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
