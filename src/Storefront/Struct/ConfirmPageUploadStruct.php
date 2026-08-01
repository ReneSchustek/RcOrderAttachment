<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Storefront\Struct;

use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUpload;
use Shopware\Core\Framework\Struct\Struct;

/**
 * Datenträger für das Twig-Template auf der Confirm-Page. Bündelt die geltende
 * Konfiguration und die bereits hochgeladenen Pending-Files in einem Struct,
 * damit das Template eine einzige, klare Quelle hat.
 */
final class ConfirmPageUploadStruct extends Struct
{
    /**
     * @param list<PendingUpload> $pendingUploads
     */
    public function __construct(
        private readonly PluginConfig $config,
        private readonly array $pendingUploads,
    ) {
    }

    public function getConfig(): PluginConfig
    {
        return $this->config;
    }

    /**
     * @return list<PendingUpload>
     */
    public function getPendingUploads(): array
    {
        return $this->pendingUploads;
    }

    public function hasUploads(): bool
    {
        return $this->pendingUploads !== [];
    }

    public function getRemainingFiles(): int
    {
        return max(0, $this->config->maxFiles - \count($this->pendingUploads));
    }

    public function getRemainingBytes(): int
    {
        $used = 0;
        foreach ($this->pendingUploads as $upload) {
            $used += $upload->fileSize;
        }

        return max(0, $this->config->maxTotalSizeBytes() - $used);
    }

    public function getAllowedExtensionsCsv(): string
    {
        return implode(', ', $this->config->allowedExtensions);
    }

    /**
     * Hilft dem `accept`-Attribut im HTML — Browser akzeptieren leading-dot Endungen.
     */
    public function getAcceptAttribute(): string
    {
        return implode(',', array_map(
            static fn (string $ext): string => '.' . $ext,
            $this->config->allowedExtensions
        ));
    }

    public function getApiAlias(): string
    {
        return 'rc_order_attachment_confirm_struct';
    }
}
