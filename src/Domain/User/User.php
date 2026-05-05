<?php
declare(strict_types=1);

namespace App\Domain\User;

use JsonSerializable;

class User implements JsonSerializable
{
    public function __construct(
        private int     $id,
        private string  $name,
        private string  $email,
        private string  $passwordHash,
        private string  $plan,
        private int     $credits,
        private bool    $isActive,
        private ?string $emailVerifiedAt,
        private string  $createdAt,
    ) {}

    public function getId(): int            { return $this->id; }
    public function getName(): string       { return $this->name; }
    public function getEmail(): string      { return $this->email; }
    public function getPasswordHash(): string { return $this->passwordHash; }
    public function getPlan(): string       { return $this->plan; }
    public function getCredits(): int       { return $this->credits; }
    public function isActive(): bool        { return $this->isActive; }
    public function isEmailVerified(): bool { return $this->emailVerifiedAt !== null; }

    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'email'             => $this->email,
            'plan'              => $this->plan,
            'credits'           => $this->credits,
            'email_verified_at' => $this->emailVerifiedAt,
            'created_at'        => $this->createdAt,
        ];
    }
}