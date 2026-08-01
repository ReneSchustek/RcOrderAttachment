<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Prüft die Datei-Endung gegen die in der Plugin-Konfiguration definierte Whitelist.
 *
 * Die Endung wird strikt aus dem Original-Filename des Uploads abgeleitet —
 * der MIME-Typ aus dem Browser ist client-kontrolliert und nicht vertrauenswürdig.
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
