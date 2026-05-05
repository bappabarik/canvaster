<?php
declare(strict_types=1);

namespace App\Domain\Auth;

interface RefreshTokenRepository
{
    public function store(int $userId, string $tokenHash, int $ttlSeconds): void;
    public function findValid(string $tokenHash): ?array;
    public function revoke(string $tokenHash): void;
    public function revokeAllForUser(int $userId): void;
}