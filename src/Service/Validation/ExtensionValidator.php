<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Prüft die Endung gegen die Liste erlaubter Endungen aus der Plugin-Konfiguration.
 *
 * Die Endung kommt aus dem ursprünglichen Dateinamen. Den MIME-Typ, den der Browser mitschickt,
 * bestimmt der Absender selbst; ihn prüft erst {@see MimeTypeValidator} über die erkannte Art.
 */
final class ExtensionValidator
{
    public function validate(UploadedFile $file, PluginConfig $config, ValidationResult $result): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === '') {
            $result->fail(ValidationCode::EXTENSION_NOT_ALLOWED, ['extension' => '(leer)']);

            return;
        }

        if (!$config->isExtensionAllowed($extension)) {
            $result->fail(ValidationCode::EXTENSION_NOT_ALLOWED, [
                'extension' => $extension,
                'allowed' => implode(', ', $config->allowedExtensions),
            ]);
        }
    }
}
