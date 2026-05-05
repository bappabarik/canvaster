<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence\Auth;

use App\Domain\Auth\RefreshTokenRepository;
use PDO;

class PdoRefreshTokenRepository implements RefreshTokenRepository
{
    public function __construct(private PDO $pdo) {}

    public function store(int $userId, string $tokenHash, int $ttlSeconds): void
    {
        $this->pdo->prepare(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))'
        )->execute([$userId, $tokenHash, $ttlSeconds]);
    }

    public function findValid(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM refresh_tokens
             WHERE token_hash = ? AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function revoke(string $tokenHash): void
    {
        $this->pdo->prepare(
            'DELETE FROM refresh_tokens WHERE token_hash = ?'
        )->execute([$tokenHash]);
    }

    public function revokeAllForUser(int $userId): void
    {
        $this->pdo->prepare(
            'DELETE FROM refresh_tokens WHERE user_id = ?'
        )->execute([$userId]);
    }
}