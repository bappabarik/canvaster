<?php
declare(strict_types=1);

namespace App\Domain\Template;

interface TemplateRepository
{
    public function findById(int $id): ?Template;
    public function findByUser(int $userId): array;
    public function findGallery(string $category = '', string $tag = ''): array;
    public function create(array $data): Template;
    public function update(int $id, array $data): Template;
    public function delete(int $id): void;
    public function belongsToUser(int $templateId, int $userId): bool;
}