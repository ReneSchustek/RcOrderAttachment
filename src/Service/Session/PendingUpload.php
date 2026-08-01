<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Session;

use JsonSerializable;

/**
 * Eintrag im {@see PendingUploadStore}. Enthält genug Information, um die Datei
 * nach Order-Abschluss zu verlinken und vor Abschluss wieder zu löschen.
 */
final readonly class PendingUpload implements JsonSerializable
{
    public function __construct(
        public string $token,
        public string $mediaId,
        public string $originalFileName,
        public string $mimeType,
        public int $fileSize,
        public int $uploadedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $token = $data['token'] ?? null;
        $mediaId = $data['mediaId'] ?? null;
        $name = $data['originalFileName'] ?? null;
        $mime = $data['mimeType'] ?? null;
        $size = $data['fileSize'] ?? null;
        $uploadedAt = $data['uploadedAt'] ?? null;

        if (!\is_string($token) || !\is_string($mediaId) || !\is_string($name)
            || !\is_string($mime) || !\is_int($size) || !\is_int($uploadedAt)
        ) {
            return null;
        }

        return new self($token, $mediaId, $name, $mime, $size, $uploadedAt);
    }

    /**
     * @return array{token: string, mediaId: string, originalFileName: string, mimeType: string, fileSize: int, uploadedAt: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'token' => $this->token,
            'mediaId' => $this->mediaId,
            'originalFileName' => $this->originalFileName,
            'mimeType' => $this->mimeType,
            'fileSize' => $this->fileSize,
            'uploadedAt' => $this->uploadedAt,
        ];
    }
}
