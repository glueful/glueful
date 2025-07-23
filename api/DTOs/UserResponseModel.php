<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Serialization\Attributes\{Groups, SerializedName, DateFormat, MaxDepth};

/**
 * User Response Model
 *
 * Specialized DTO for API responses with carefully controlled serialization
 * groups to ensure appropriate data exposure at different access levels.
 */
class UserResponseModel
{
    #[Groups(['public', 'authenticated', 'detailed'])]
    public string $id;

    #[Groups(['public', 'authenticated', 'detailed'])]
    public string $name;

    #[Groups(['public', 'authenticated', 'detailed'])]
    public ?string $username = null;

    #[Groups(['authenticated', 'detailed'])]
    public string $email;

    #[Groups(['public', 'authenticated', 'detailed'])]
    public string $status;

    #[Groups(['authenticated', 'detailed'])]
    public string $role;

    #[Groups(['public', 'authenticated', 'detailed'])]
    #[SerializedName('created_at')]
    #[DateFormat('c')] // ISO 8601 format
    public \DateTime $createdAt;

    #[Groups(['authenticated', 'detailed'])]
    #[SerializedName('updated_at')]
    #[DateFormat('c')]
    public \DateTime $updatedAt;

    #[Groups(['authenticated', 'detailed'])]
    #[SerializedName('last_login')]
    #[DateFormat('c')]
    public ?\DateTime $lastLogin = null;

    #[Groups(['public', 'authenticated', 'detailed'])]
    public ?string $avatar = null;

    #[Groups(['public', 'authenticated', 'detailed'])]
    public ?string $bio = null;

    #[Groups(['public', 'authenticated', 'detailed'])]
    public ?string $location = null;

    #[Groups(['public', 'authenticated', 'detailed'])]
    public ?string $website = null;

    #[Groups(['authenticated', 'detailed'])]
    #[SerializedName('phone_number')]
    public ?string $phoneNumber = null;

    #[Groups(['authenticated', 'detailed'])]
    #[SerializedName('date_of_birth')]
    #[DateFormat('Y-m-d')]
    public ?\DateTime $dateOfBirth = null;

    #[Groups(['detailed'])]
    public array $preferences = [];

    #[Groups(['detailed'])]
    public array $permissions = [];

    #[Groups(['public', 'authenticated', 'detailed'])]
    #[SerializedName('is_verified')]
    public bool $isVerified = false;

    #[Groups(['public', 'authenticated', 'detailed'])]
    #[SerializedName('is_online')]
    public bool $isOnline = false;

    #[Groups(['authenticated', 'detailed'])]
    #[SerializedName('profile_completed')]
    public bool $profileCompleted = false;

    #[Groups(['detailed'])]
    #[MaxDepth(2)]
    public ?UserResponseModel $manager = null;

    #[Groups(['detailed'])]
    #[MaxDepth(1)]
    public array $subordinates = [];

    #[Groups(['detailed'])]
    #[SerializedName('member_since')]
    #[DateFormat('Y-m-d')]
    public \DateTime $memberSince;

    #[Groups(['detailed'])]
    #[SerializedName('total_posts')]
    public int $totalPosts = 0;

    #[Groups(['detailed'])]
    #[SerializedName('total_comments')]
    public int $totalComments = 0;

    #[Groups(['detailed'])]
    #[SerializedName('reputation_score')]
    public int $reputationScore = 0;

    #[Groups(['admin'])]
    #[SerializedName('internal_id')]
    public ?string $internalId = null;

    #[Groups(['admin'])]
    #[SerializedName('internal_notes')]
    public ?string $internalNotes = null;

    #[Groups(['admin'])]
    #[SerializedName('ip_address')]
    public ?string $ipAddress = null;

    #[Groups(['admin'])]
    #[SerializedName('user_agent')]
    public ?string $userAgent = null;

    #[Groups(['admin'])]
    #[SerializedName('login_attempts')]
    public int $loginAttempts = 0;

    #[Groups(['admin'])]
    #[SerializedName('last_ip')]
    public ?string $lastIp = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->memberSince = new \DateTime();
    }

    /**
     * Create from UserDTO
     */
    public static function fromUserDTO(UserDTO $user): self
    {
        $response = new self();

        // Map basic properties
        $response->id = $user->username ?? uniqid();
        $response->name = $user->name;
        $response->username = $user->username;
        $response->email = $user->email;
        $response->status = $user->status;
        $response->role = $user->role;
        $response->avatar = $user->avatar;
        $response->bio = $user->bio;
        $response->location = $user->location;
        $response->website = $user->website;
        $response->phoneNumber = $user->phoneNumber;
        $response->dateOfBirth = $user->dateOfBirth;
        $response->preferences = $user->preferences;
        $response->permissions = $user->permissions;
        $response->isVerified = $user->isVerified;
        $response->isOnline = $user->isOnline;
        $response->profileCompleted = $user->profileCompleted;
        $response->internalNotes = $user->internalNotes;
        $response->ipAddress = $user->ipAddress;
        $response->userAgent = $user->userAgent;

        // Handle dates
        if ($user->createdAt) {
            $response->createdAt = $user->createdAt;
            $response->memberSince = $user->createdAt;
        }
        if ($user->updatedAt) {
            $response->updatedAt = $user->updatedAt;
        }
        if ($user->lastLogin) {
            $response->lastLogin = $user->lastLogin;
        }

        return $response;
    }

    /**
     * Get public profile data
     */
    public function getPublicProfile(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            'location' => $this->location,
            'website' => $this->website,
            'is_verified' => $this->isVerified,
            'is_online' => $this->isOnline,
            'member_since' => $this->memberSince->format('Y-m-d'),
            'total_posts' => $this->totalPosts,
            'reputation_score' => $this->reputationScore,
        ];
    }

    /**
     * Get summary for lists
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'is_verified' => $this->isVerified,
            'is_online' => $this->isOnline,
        ];
    }
}
