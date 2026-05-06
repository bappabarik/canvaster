<?php
declare(strict_types=1);

namespace App\Domain\Project;

interface ProjectRepository
{
    public function findById(int $id): ?Project;
    public function findByUser(int $userId): array;
    public function create(array $data): Project;
    public function update(int $id, array $data): Project;
    public function belongsToUser(int $projectId, int $userId): bool;

    // Row operations
    public function insertRows(int $projectId, array $rows): void;
    public function getRows(int $projectId, int $limit, int $offset): array;
    public function getRow(int $projectId, int $rowIndex): ?array;
    public function updateRowStatus(int $projectId, int $rowIndex, string $status, ?string $outputPath, ?string $errorMsg): void;

    // Job operations
    public function createJob(int $projectId): array;
    public function getJob(int $projectId): ?array;
    public function updateJob(int $jobId, array $data): void;
}