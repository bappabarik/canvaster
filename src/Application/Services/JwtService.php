<?php
declare(strict_types=1);

namespace App\Application\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    private string $secret;
    private int    $accessTtl;
    private int    $refreshTtl;

    public function __construct(array $jwtConfig)
    {
        $this->secret     = $jwtConfig['secret'];
        $this->accessTtl  = $jwtConfig['access_ttl'];
        $this->refreshTtl = $jwtConfig['refresh_ttl'];
    }

    public function issueAccessToken(int $userId, string $email, string $plan): string
    {
        $now = time();
        return JWT::encode([
            'sub'   => $userId,
            'email' => $email,
            'plan'  => $plan,
            'iat'   => $now,
            'exp'   => $now + $this->accessTtl,
        ], $this->secret, 'HS256');
    }

    public function issueRefreshToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function decode(string $token): array
    {
        return (array) JWT::decode($token, new Key($this->secret, 'HS256'));
    }

    public function getAccessTtl(): int  { return $this->accessTtl; }
    public function getRefreshTtl(): int { return $this->refreshTtl; }
}