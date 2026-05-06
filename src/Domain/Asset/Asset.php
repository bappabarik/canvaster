<?php
declare(strict_types=1);

namespace App\Domain\Asset;

class Asset
{
    public function __construct(
        private int     $id,
        private int     $userId,
        private ?int    $projectId,
        private string  $originalFilename,
        private string  $cloudinaryPublicId,
        private string  $cloudinaryUrl,
        private string  $assetType,
        private int     $fileSizeBytes,
        private string  $createdAt,
    ) {}

    public function getId(): int                  { return $this->id; }
    public function getUserId(): int              { return $this->userId; }
    public function getProjectId(): ?int          { return $this->projectId; }
    public function getOriginalFilename(): string { return $this->originalFilename; }
    public function getCloudinaryPublicId(): string { return $this->cloudinaryPublicId; }
    public function getCloudinaryUrl(): string    { return $this->cloudinaryUrl; }
    public function getAssetType(): string        { return $this->assetType; }
    public function getFileSizeBytes(): int       { return $this->fileSizeBytes; }
    public function getCreatedAt(): string        { return $this->createdAt; }

    public function jsonSerialize(): array
    {
        return [
            'id'                    => $this->id,
            'user_id'               => $this->userId,
            'project_id'            => $this->projectId,
            'original_filename'     => $this->originalFilename,
            'cloudinary_public_id'  => $this->cloudinaryPublicId,
            'cloudinary_url'        => $this->cloudinaryUrl,
            'asset_type'            => $this->assetType,
            'file_size_bytes'       => $this->fileSizeBytes,
            'created_at'            => $this->createdAt,
        ];
    }
}