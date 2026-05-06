<?php
declare(strict_types=1);

namespace App\Domain\Project;

use JsonSerializable;

class Project implements JsonSerializable
{
    public function __construct(
        private int     $id,
        private int     $userId,
        private int     $templateId,
        private string  $canvasSnapshotJson,
        private array   $placeholdersSnapshot,
        private string  $name,
        private ?string $csvPath,
        private ?array  $columnMap,
        private ?string $imageZipPath,
        private int     $totalRows,
        private string  $outputFormat,
        private string  $status,
        private string  $createdAt,
        private string  $updatedAt,
    ) {}

    public function getId(): int              { return $this->id; }
    public function getUserId(): int          { return $this->userId; }
    public function getTemplateId(): int      { return $this->templateId; }
    public function getCanvasSnapshotJson(): string { return $this->canvasSnapshotJson; }
    public function getPlaceholdersSnapshot(): array { return $this->placeholdersSnapshot; }
    public function getName(): string         { return $this->name; }
    public function getCsvPath(): ?string     { return $this->csvPath; }
    public function getColumnMap(): ?array    { return $this->columnMap; }
    public function getImageZipPath(): ?string { return $this->imageZipPath; }
    public function getTotalRows(): int       { return $this->totalRows; }
    public function getOutputFormat(): string { return $this->outputFormat; }
    public function getStatus(): string       { return $this->status; }

    public function jsonSerialize(): array
    {
        return [
            'id'                    => $this->id,
            'user_id'               => $this->userId,
            'template_id'           => $this->templateId,
            'placeholders_snapshot' => $this->placeholdersSnapshot,
            'name'                  => $this->name,
            'csv_path'              => $this->csvPath,
            'column_map'            => $this->columnMap,
            'image_zip_path'        => $this->imageZipPath,
            'total_rows'            => $this->totalRows,
            'output_format'         => $this->outputFormat,
            'status'                => $this->status,
            'created_at'            => $this->createdAt,
            'updated_at'            => $this->updatedAt,
        ];
    }
}