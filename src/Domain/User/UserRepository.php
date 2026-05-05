<?php
declare(strict_types=1);

namespace App\Domain\User;

interface UserRepository
{
    public function findById(int $id): ?User;
    public function findByEmail(string $email): ?User;
    public function emailExists(string $email): bool;
    public function create(string $name, string $email, string $passwordHash): User;
}