<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Validation\Attributes\Sanitize;
use Glueful\Validation\Constraints\{Required, Email, StringLength, Choice};
use Glueful\Serialization\Attributes\{Groups, SerializedName, Ignore, DateFormat, MaxDepth};

/**
 * Enhanced User Data Transfer Object
 *
 * Modern DTO with comprehensive validation and serialization attributes
 * supporting multiple contexts and security controls.
 */
class UserDTO
{
    #[Sanitize(['trim', 'strip_tags'])]
    #[Required]
    #[StringLength(min: 2, max: 50)]
    #[Groups(['user:read', 'user:write', 'user:public'])]
    public string $name;

    #[Sanitize(['trim', 'strip_tags'])]
    #[Required]
    #[Email(message: 'Please provide a valid email address')]
    #[Groups(['user:read', 'user:write', 'user:private'])]
    public string $email;

    #[Sanitize(['trim'])]
    #[StringLength(min: 8, max: 255)]
    #[Required(groups: ['user:create'])]
    #[Ignore] // Never serialize password
    public ?string $password = null;

    #[Sanitize(['trim', 'strip_tags'])]
    #[StringLength(min: 3, max: 30)]
    #[Groups(['user:read', 'user:write', 'user:public'])]
    public ?string $username = null;

    #[Choice(['active', 'inactive', 'suspended', 'banned'])]
    #[Groups(['user:read', 'admin:read'])]
    public string $status = 'active';

    #[Choice(['user', 'admin', 'moderator', 'guest'])]
    #[Groups(['user:read', 'admin:read'])]
    public string $role = 'user';

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('created_at')]
    #[DateFormat('Y-m-d H:i:s')]
    public ?\DateTime $createdAt = null;

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('updated_at')]
    #[DateFormat('Y-m-d H:i:s')]
    public ?\DateTime $updatedAt = null;

    #[Groups(['user:read', 'admin:read'])]
    #[SerializedName('last_login')]
    #[DateFormat('c')] // ISO 8601 format
    public ?\DateTime $lastLogin = null;

    #[Groups(['user:read', 'user:private'])]
    public ?string $avatar = null;

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('phone_number')]
    public ?string $phoneNumber = null;

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('date_of_birth')]
    #[DateFormat('Y-m-d')]
    public ?\DateTime $dateOfBirth = null;

    #[Groups(['user:read', 'user:private'])]
    public ?string $bio = null;

    #[Groups(['user:read', 'user:private'])]
    public ?string $location = null;

    #[Groups(['user:read', 'user:private'])]
    public ?string $website = null;

    #[Groups(['user:read', 'user:private'])]
    public array $preferences = [];

    #[Groups(['user:read', 'user:private'])]
    public array $permissions = [];

    #[Groups(['admin:read'])]
    #[SerializedName('internal_notes')]
    public ?string $internalNotes = null;

    #[Groups(['admin:read'])]
    #[SerializedName('ip_address')]
    public ?string $ipAddress = null;

    #[Groups(['admin:read'])]
    #[SerializedName('user_agent')]
    public ?string $userAgent = null;

    #[Groups(['user:detailed'])]
    #[MaxDepth(2)]
    public ?UserDTO $manager = null;

    #[Groups(['user:detailed'])]
    #[MaxDepth(3)]
    public array $subordinates = [];

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('is_verified')]
    public bool $isVerified = false;

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('is_online')]
    public bool $isOnline = false;

    #[Groups(['user:read', 'user:private'])]
    #[SerializedName('profile_completed')]
    public bool $profileCompleted = false;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    /**
     * Create a DTO from array data
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();

        foreach ($data as $key => $value) {
            if (property_exists($dto, $key)) {
                $dto->$key = $value;
            }
        }

        return $dto;
    }

    /**
     * Get public representation
     */
    public function getPublicData(): array
    {
        return [
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            'location' => $this->location,
            'website' => $this->website,
            'is_verified' => $this->isVerified,
            'is_online' => $this->isOnline,
        ];
    }

    /**
     * Check if user has permission
     */
    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * Check if user has role
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Get user's full name for display
     */
    public function getDisplayName(): string
    {
        return $this->name ?: $this->username ?: 'Anonymous';
    }

    /**
     * Check if profile is complete
     */
    public function isProfileComplete(): bool
    {
        return !empty($this->name) &&
               !empty($this->email) &&
               !empty($this->username) &&
               $this->profileCompleted;
    }
}
