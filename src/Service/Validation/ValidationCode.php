<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

/**
 * Maschinenlesbare Ablehnungsgründe der Prüfkette.
 *
 * Jeder Wert ist zugleich der letzte Teil eines Snippet-Schlüssels
 * (`rcOrderAttachment.error.<code>`). Ein neuer Fall braucht deshalb auch einen Eintrag in den
 * Snippets und in der Vorlage der Bestätigungsseite.
 */
enum ValidationCode: string
{
    case PLUGIN_DISABLED = 'pluginDisabled';
    case EMPTY_UPLOAD = 'emptyUpload';
    case UPLOAD_ERROR = 'uploadError';
    case EXTENSION_NOT_ALLOWED = 'extensionNotAllowed';
    case EXTENSION_BLACKLISTED = 'extensionBlacklisted';
    case MIME_NOT_ALLOWED = 'mimeNotAllowed';
    case MAGIC_BYTES_MISMATCH = 'magicBytesMismatch';
    case FILE_SIZE_EXCEEDED = 'fileSizeExceeded';
    case TOTAL_SIZE_EXCEEDED = 'totalSizeExceeded';
    case FILE_COUNT_EXCEEDED = 'fileCountExceeded';
    case DANGEROUS_CONTENT_DETECTED = 'dangerousContentDetected';
    /**
     * Kein Sitzungskontext: Die Datei ließe sich später keiner Bestellung zuordnen.
     * Der Upload wird deshalb abgelehnt, statt Erfolg zu melden und die Datei zu verlieren.
     */
    case SESSION_UNAVAILABLE = 'sessionUnavailable';
}
