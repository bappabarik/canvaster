<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Asset;

use App\Domain\Asset\Asset;
use App\Domain\Asset\AssetRepository;
use PDO;

class PdoAssetRepository implements AssetRepository
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $id): ?Asset
    {
        $stmt = $this->pdo->prepare('SELECT * FROM uploaded_assets WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findByUser(int $userId, string $assetType = ''): array
    {
        if ($assetType !== '') {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM uploaded_assets
                 WHERE user_id = ? AND asset_type = ?
                 ORDER BY created_at DESC'
            );
            $stmt->execute([$userId, $assetType]);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM uploaded_assets
                 WHERE user_id = ?
                 ORDER BY created_at DESC'
            );
            $stmt->execute([$userId]);
        }

        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll());
    }

    public function findByProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM uploaded_assets
             WHERE project_id = ?
             ORDER BY asset_type, created_at DESC'
        );
        $stmt->execute([$projectId]);
        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll());
    }

    public function create(array $data): Asset
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO uploaded_assets
             (user_id, project_id, original_filename, cloudinary_public_id,
              cloudinary_url, asset_type, file_size_bytes)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['user_id'],
            $data['project_id']           ?? null,
            $data['original_filename'],
            $data['cloudinary_public_id'],
            $data['cloudinary_url'],
            $data['asset_type']           ?? 'row_image',
            $data['file_size_bytes']      ?? 0,
        ]);

        return $this->findById((int) $this->pdo->lastInsertId());
    }

    // When a user reuses an existing CSV for a new project,
    // we update the asset's project_id to the new project.
    // If you want one asset linked to multiple projects in future,
    // add a pivot table — for now single project_id is sufficient.
    public function linkToProject(int $assetId, int $projectId): void
    {
        $this->pdo->prepare(
            'UPDATE uploaded_assets SET project_id = ? WHERE id = ?'
        )->execute([$projectId, $assetId]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM uploaded_assets WHERE id = ?')->execute([$id]);
    }

    private function hydrate(array $row): Asset
    {
        return new Asset(
            id:                 (int) $row['id'],
            userId:             (int) $row['user_id'],
            projectId:          $row['project_id'] ? (int) $row['project_id'] : null,
            originalFilename:   $row['original_filename'],
            cloudinaryPublicId: $row['cloudinary_public_id'],
            cloudinaryUrl:      $row['cloudinary_url'],
            assetType:          $row['asset_type'],
            fileSizeBytes:      (int) $row['file_size_bytes'],
            createdAt:          $row['created_at'],
        );
    }
}