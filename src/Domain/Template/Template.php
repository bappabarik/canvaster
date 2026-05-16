<?php
declare(strict_types=1);

namespace App\Domain\Template;

use JsonSerializable;

class Template implements JsonSerializable
{
    public function __construct(
        private int     $id,
        private ?int    $userId,
        private string  $name,
        private string  $category,
        private string  $canvasJson,
        private array   $placeholders,
        private int     $widthPx,
        private int     $heightPx,
        private ?string $thumbnailUrl,
        private bool    $isPublic,
        private string  $createdAt,
        private string  $updatedAt,
    ) {}

    public function getId(): int           { return $this->id; }
    public function getUserId(): ?int      { return $this->userId; }
    public function getName(): string      { return $this->name; }
    public function getCategory(): string  { return $this->category; }
    public function getCanvasJson(): string { return $this->canvasJson; }
    public function getPlaceholders(): array { return $this->placeholders; }
    public function isPublic(): bool       { return $this->isPublic; }
    public function getWidthPx(): int       { return $this->widthPx; }
    public function getHeightPx(): int       { return $this->heightPx; }

    public function jsonSerialize(): array
    {
        return [
            'id'           => $this->id,
            'user_id'      => $this->userId,
            'name'         => $this->name,
            'category'     => $this->category,
            'canvas_json'  => $this->canvasJson,
            'placeholders' => $this->placeholders,
            'width_px'     => $this->widthPx,
            'height_px'    => $this->heightPx,
            'thumbnail_url'=> $this->thumbnailUrl,
            'is_public'    => $this->isPublic,
            'created_at'   => $this->createdAt,
            'updated_at'   => $this->updatedAt,
        ];
    }
}