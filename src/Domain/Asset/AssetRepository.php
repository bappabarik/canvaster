<?php
declare(strict_types=1);

namespace App\Domain\Asset;

interface AssetRepository
{
    public function findById(int $id): ?Asset;

    /** All assets belonging to a user, optionally filtered by type */
    public function findByUser(int $userId, string $assetType = ''): array;

    /** All assets linked to a specific project */
    public function findByProject(int $projectId): array;

    public function create(array $data): Asset;

    /** Link an existing asset to an additional project */
    public function linkToProject(int $assetId, int $projectId): void;

    public function delete(int $id): void;
}