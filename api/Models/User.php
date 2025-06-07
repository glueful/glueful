<?php

declare(strict_types=1);

namespace Glueful\Models;

class User
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $username,
        public readonly string $email,
        public readonly string $status,
        public readonly ?string $lastLogin = null,
        public readonly array $roles = [],
        public readonly array $profile = [],
        public readonly bool $isAdmin = false,
        public readonly bool $rememberMe = false,
        public readonly ?string $createdAt = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            uuid: $data['uuid'],
            username: $data['username'],
            email: $data['email'],
            status: $data['status'],
            lastLogin: $data['last_login'] ?? null,
            roles: $data['roles'] ?? [],
            profile: $data['profile'] ?? [],
            isAdmin: $data['is_admin'] ?? false,
            rememberMe: $data['remember_me'] ?? false,
            createdAt: $data['created_at'] ?? null
        );
    }

    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'username' => $this->username,
            'email' => $this->email,
            'status' => $this->status,
            'last_login' => $this->lastLogin,
            'roles' => $this->roles,
            'profile' => $this->profile,
            'is_admin' => $this->isAdmin,
            'remember_me' => $this->rememberMe,
            'created_at' => $this->createdAt
        ];
    }
}
