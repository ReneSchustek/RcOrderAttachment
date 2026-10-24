<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Feste Sperrliste gefährlicher Endungen und MIME-Typen, die auch dann greift, wenn jemand in der
 * Verwaltung versehentlich `php` freigibt. Gegen die erlaubte Endungsliste behält sie das letzte Wort.
 *
 * Zusätzlich sucht sie am Dateianfang nach Skriptkennungen, um Dateien abzufangen, die unter
 * einer zulässigen Endung zugleich als Skript taugen.
 */
final class DangerousContentValidator
{
    /**
     * Öffentlich, damit {@see \Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider}
     * dieselbe Liste schon beim Lesen der Konfiguration anwendet. Sonst gelangte eine in der
     * Verwaltung eingetragene Endung über den `MediaWhitelistSubscriber` in die Endungsliste des
     * Kerns, ohne je diesen Prüfer zu durchlaufen.
     *
     * @var list<string>
     */
    public const FORBIDDEN_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht',
        'htaccess', 'htpasswd',
        'sh', 'bash', 'zsh', 'ksh', 'csh',
        'exe', 'bat', 'cmd', 'com', 'ps1', 'psm1', 'vbs', 'vbe', 'wsf', 'wsh',
        'js', 'mjs', 'jse',
        'jsp', 'jspx', 'asp', 'aspx', 'cfm', 'cfc', 'pl', 'cgi',
        'html', 'htm', 'xhtml', 'shtml',
        'svg', 'svgz',
        'hta',
        'dll', 'msi', 'msp', 'scr',
        'jar', 'class',
        'py', 'rb',
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN_MIME_PREFIXES = [
        'text/html',
        'application/xhtml',
        'application/x-php',
        'application/x-httpd-php',
        'application/javascript',
        'application/x-javascript',
        'application/x-sh',
        'application/x-shellscript',
        'application/x-msdownload',
        'application/x-msi',
        'image/svg',
    ];

    /**
     * @var list<string>
     */
    private const SCRIPT_SIGNATURES = [
        '<?php',
        '<?=',
        '<script',
        '#!/',
    ];

    public function validate(UploadedFile $file, ValidationResult $result): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension !== '' && \in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            $result->fail(ValidationCode::EXTENSION_BLACKLISTED, ['extension' => $extension]);

            return;
        }

        if ($this->mimeMatchesForbidden($file->getMimeType())) {
            $result->fail(ValidationCode::EXTENSION_BLACKLISTED, [
                'mime' => $file->getMimeType() ?? '',
            ]);

            return;
        }

        if ($this->containsScriptSignature($file)) {
            $result->fail(ValidationCode::DANGEROUS_CONTENT_DETECTED);
        }
    }

    private function mimeMatchesForbidden(?string $mime): bool
    {
        if ($mime === null || $mime === '') {
            return false;
        }
        $normalized = strtolower($mime);
        foreach (self::FORBIDDEN_MIME_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function containsScriptSignature(UploadedFile $file): bool
    {
        $path = $file->getRealPath();
        if ($path === false || !is_readable($path)) {
            return false;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        // Die ersten 2 KiB fangen Kennungen am Dateianfang. Ein Skript weiter hinten bleibt
        // unentdeckt; ein größeres Fenster erhöht dafür die Gefahr, dass Binärdaten zufällig eine
        // der kurzen Kennungen wie `<?=` oder `#!/` enthalten.
        $head = (string) fread($handle, 2048);
        fclose($handle);

        if ($head === '') {
            return false;
        }

        $lower = strtolower($head);
        foreach (self::SCRIPT_SIGNATURES as $signature) {
            if (str_contains($lower, $signature)) {
                return true;
            }
        }

        return false;
    }
}
