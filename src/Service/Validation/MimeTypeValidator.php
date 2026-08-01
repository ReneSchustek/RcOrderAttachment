<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Vergleicht den server-seitig erkannten MIME-Typ mit den Erwartungen für die
 * Endung. Die Erkennung läuft über `finfo` (von Symfony in UploadedFile::getMimeType
 * gekapselt) — der vom Browser gesendete `$_FILES['type']` wird NICHT verwendet.
 */
final class MimeTypeValidator
{
    /**
     * Erwartete MIME-Typen je erlaubter Endung. Mehrere Werte sind zulässig
     * (z. B. `image/jpeg` und der inoffizielle `image/pjpeg`).
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED_MIMES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png', 'image/x-png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'tif' => ['image/tiff'],
        'tiff' => ['image/tiff'],
        'bmp' => ['image/bmp', 'image/x-ms-bmp'],
        'heic' => ['image/heic'],
        'heif' => ['image/heif'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'dxf' => ['image/vnd.dxf', 'application/dxf', 'text/plain', 'application/octet-stream'],
        'dwg' => ['image/vnd.dwg', 'application/acad', 'application/octet-stream'],
        'step' => ['model/step', 'application/step', 'text/plain', 'application/octet-stream'],
        'stp' => ['model/step', 'application/step', 'text/plain', 'application/octet-stream'],
        'iges' => ['model/iges', 'application/iges', 'text/plain', 'application/octet-stream'],
        'igs' => ['model/iges', 'application/iges', 'text/plain', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    public function validate(UploadedFile $file, ValidationResult $result): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === '' || !isset(self::EXPECTED_MIMES[$extension])) {
            // Keine konkrete Erwartung definiert → nicht hier ablehnen.
            // Whitelist/Magic-Bytes haben das letzte Wort.
            return;
        }

        $detected = $file->getMimeType();
        if ($detected === null) {
            $result->fail(ValidationCode::MIME_NOT_ALLOWED, [
                'extension' => $extension,
                'detected' => '(unbekannt)',
            ]);

            return;
        }

        $expected = self::EXPECTED_MIMES[$extension];
        if (!\in_array(strtolower($detected), $expected, true)) {
            $result->fail(ValidationCode::MIME_NOT_ALLOWED, [
                'extension' => $extension,
                'detected' => $detected,
                'expected' => implode(', ', $expected),
            ]);
        }
    }
}
