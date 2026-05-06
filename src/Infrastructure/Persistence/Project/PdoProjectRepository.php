<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Project;

use App\Domain\Project\Project;
use App\Domain\Project\ProjectRepository;
use PDO;

class PdoProjectRepository implements ProjectRepository
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $id): ?Project
    {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM projects WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return array_map(fn($r) => $this->hydrate($r), $stmt->fetchAll());
    }

    public function create(array $data): Project
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO projects
             (user_id, template_id, canvas_snapshot_json, placeholders_snapshot,
              name, output_format, status)
             VALUES (?, ?, ?, ?, ?, ?, "draft")'
        );
        $stmt->execute([
            $data['user_id'],
            $data['template_id'],
            $data['canvas_snapshot_json'],
            json_encode($data['placeholders_snapshot']),
            $data['name'],
            $data['output_format'] ?? 'zip_png',
        ]);
        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): Project
    {
        $allowed = [
            'csv_path', 'column_map', 'image_zip_path',
            'total_rows', 'output_format', 'status',
        ];
        $sets   = [];
        $values = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            $sets[]   = "{$key} = ?";
            $values[] = ($key === 'column_map') ? json_encode($value) : $value;
        }

        if (!empty($sets)) {
            $values[] = $id;
            $this->pdo->prepare(
                'UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = ?'
            )->execute($values);
        }

        return $this->findById($id);
    }

    public function belongsToUser(int $projectId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM projects WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    // ── Row operations ────────────────────────────────────────────

    public function insertRows(int $projectId, array $rows): void
    {
        // Batch insert in chunks of 500 to handle large CSVs efficiently
        $chunks = array_chunk($rows, 500);

        foreach ($chunks as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '(?, ?, ?)'));
            $values       = [];

            foreach ($chunk as $row) {
                $values[] = $projectId;
                $values[] = $row['row_index'];
                $values[] = json_encode($row['data']);
            }

            $this->pdo->prepare(
                "INSERT IGNORE INTO project_rows (project_id, row_index, data)
                 VALUES {$placeholders}"
            )->execute($values);
        }
    }

    public function getRows(int $projectId, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM project_rows
             WHERE project_id = ?
             ORDER BY row_index ASC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$projectId, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public function getRow(int $projectId, int $rowIndex): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM project_rows
             WHERE project_id = ? AND row_index = ? LIMIT 1'
        );
        $stmt->execute([$projectId, $rowIndex]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateRowStatus(
        int $projectId,
        int $rowIndex,
        string $status,
        ?string $outputPath,
        ?string $errorMsg
    ): void {
        $this->pdo->prepare(
            'UPDATE project_rows
             SET status = ?, output_path = ?, error_msg = ?,
                 rendered_at = IF(? = "done", NOW(), NULL)
             WHERE project_id = ? AND row_index = ?'
        )->execute([$status, $outputPath, $errorMsg, $status, $projectId, $rowIndex]);
    }

    // ── Job operations ────────────────────────────────────────────

    public function createJob(int $projectId): array
    {
        $this->pdo->prepare(
            'INSERT INTO generation_jobs (project_id, status) VALUES (?, "queued")'
        )->execute([$projectId]);

        return $this->getJob($projectId);
    }

    public function getJob(int $projectId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM generation_jobs WHERE project_id = ? LIMIT 1'
        );
        $stmt->execute([$projectId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function updateJob(int $jobId, array $data): void
    {
        $allowed = ['status', 'rows_done', 'rows_failed', 'output_zip_path', 'started_at', 'completed_at', 'error_log'];
        $sets    = [];
        $values  = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            $sets[]   = "{$key} = ?";
            $values[] = $value;
        }

        if (!empty($sets)) {
            $values[] = $jobId;
            $this->pdo->prepare(
                'UPDATE generation_jobs SET ' . implode(', ', $sets) . ' WHERE id = ?'
            )->execute($values);
        }
    }

    private function hydrate(array $row): Project
    {
        return new Project(
            id:                   (int) $row['id'],
            userId:               (int) $row['user_id'],
            templateId:           (int) $row['template_id'],
            canvasSnapshotJson:   $row['canvas_snapshot_json'],
            placeholdersSnapshot: json_decode($row['placeholders_snapshot'], true) ?? [],
            name:                 $row['name'],
            csvPath:              $row['csv_path'],
            columnMap:            $row['column_map'] ? json_decode($row['column_map'], true) : null,
            imageZipPath:         $row['image_zip_path'],
            totalRows:            (int) $row['total_rows'],
            outputFormat:         $row['output_format'],
            status:               $row['status'],
            createdAt:            $row['created_at'],
            updatedAt:            $row['updated_at'],
        );
    }
}