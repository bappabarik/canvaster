<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\User;

use App\Domain\User\User;
use App\Domain\User\UserRepository;
use PDO;

class PdoUserRepository implements UserRepository
{
    public function __construct(private PDO $pdo) {}

    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ? $this->hydrate($row) : null;
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        return (bool) $stmt->fetchColumn();
    }

    public function create(string $name, string $email, string $passwordHash): User
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)'
        );
        $stmt->execute([$name, $email, $passwordHash]);
        return $this->findById((int) $this->pdo->lastInsertId());
    }

    private function hydrate(array $row): User
    {
        return new User(
            id:              (int) $row['id'],
            name:            $row['name'],
            email:           $row['email'],
            passwordHash:    $row['password_hash'],
            plan:            $row['plan'],
            credits:         (int) $row['credits'],
            isActive:        (bool) $row['is_active'],
            emailVerifiedAt: $row['email_verified_at'],
            createdAt:       $row['created_at'],
        );
    }
}