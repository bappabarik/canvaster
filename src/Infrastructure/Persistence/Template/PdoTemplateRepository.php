<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Template;

use App\Domain\Template\Template;
use App\Domain\Template\TemplateRepository;
use PDO;

class PdoTemplateRepository implements TemplateRepository
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $id): ?Template
    {
        $stmt = $this->pdo->prepare('SELECT * FROM templates WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findByUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM templates WHERE user_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$userId]);
        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll());
    }

    public function findGallery(string $category = '', string $tag = ''): array
    {
        // Base: only public templates
        $where  = ['t.is_public = 1'];
        $params = [];

        if ($category !== '') {
            $where[]  = 't.category = ?';
            $params[] = $category;
        }

        if ($tag !== '') {
            $where[]  = 'EXISTS (
                SELECT 1 FROM template_gallery_tags tgt
                WHERE tgt.template_id = t.id AND tgt.tag = ?
            )';
            $params[] = $tag;
        }

        $sql  = 'SELECT t.* FROM templates t WHERE '
              . implode(' AND ', $where)
              . ' ORDER BY t.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(fn($row) => $this->hydrate($row), $stmt->fetchAll());
    }

    public function create(array $data): Template
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO templates
             (user_id, name, category, canvas_json, placeholders, width_px, height_px, thumbnail_url, is_public)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['user_id'],
            $data['name'],
            $data['category']     ?? 'other',
            $data['canvas_json'],
            json_encode($data['placeholders']),
            $data['width_px']     ?? 800,
            $data['height_px']    ?? 600,
            $data['thumbnail_url'] ?? null,
            $data['is_public']    ?? 0,
        ]);
        return $this->findById((int) $this->pdo->lastInsertId());
    }

    public function update(int $id, array $data): Template
    {
        $allowed = [
            'name', 'category', 'canvas_json', 'placeholders',
            'width_px', 'height_px', 'thumbnail_url', 'is_public',
        ];
        $sets   = [];
        $values = [];

        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) continue;
            $sets[]   = "{$key} = ?";
            $values[] = ($key === 'placeholders') ? json_encode($value) : $value;
        }

        if (!empty($sets)) {
            $values[] = $id;
            $this->pdo->prepare(
                'UPDATE templates SET ' . implode(', ', $sets) . ' WHERE id = ?'
            )->execute($values);
        }

        return $this->findById($id);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM templates WHERE id = ?')->execute([$id]);
    }

    public function belongsToUser(int $templateId, int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM templates WHERE id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$templateId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    private function hydrate(array $row): Template
    {
        return new Template(
            id:           (int) $row['id'],
            userId:       $row['user_id'] ? (int) $row['user_id'] : null,
            name:         $row['name'],
            category:     $row['category'],
            canvasJson:   $row['canvas_json'],
            placeholders: json_decode($row['placeholders'], true) ?? [],
            widthPx:      (int) $row['width_px'],
            heightPx:     (int) $row['height_px'],
            thumbnailUrl: $row['thumbnail_url'],
            isPublic:     (bool) $row['is_public'],
            createdAt:    $row['created_at'],
            updatedAt:    $row['updated_at'],
        );
    }
}