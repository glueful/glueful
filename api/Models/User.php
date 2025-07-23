<?php

declare(strict_types=1);

namespace Glueful\Models;

class User
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $id, // OIDC standard ID field
        public readonly string $username,
        public readonly string $email,
        public readonly int $updatedAt,
        public readonly bool $emailVerified = false,
        public readonly string $locale = 'en-US',
        public readonly ?string $name = null,
        public readonly ?string $givenName = null,
        public readonly ?string $familyName = null,
        public readonly ?string $picture = null,
        public readonly string $status = 'active',
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
            id: (string)($data['id'] ?? $data['uuid']), // Use id field or fallback to uuid
            username: $data['username'],
            email: $data['email'],
            updatedAt: $data['updated_at'] ?? time(),
            emailVerified: $data['email_verified'] ?? false,
            locale: $data['locale'] ?? 'en-US',
            name: $data['name'] ?? null,
            givenName: $data['given_name'] ?? null,
            familyName: $data['family_name'] ?? null,
            picture: $data['picture'] ?? null,
            status: $data['status'] ?? 'active',
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
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'email_verified' => $this->emailVerified,
            'locale' => $this->locale,
            'updated_at' => $this->updatedAt,
            'name' => $this->name,
            'given_name' => $this->givenName,
            'family_name' => $this->familyName,
            'picture' => $this->picture,
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
