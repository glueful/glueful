<?php

declare(strict_types=1);

namespace Glueful\Events\Auth;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Session Destroyed Event
 *
 * Dispatched when a user session is destroyed/revoked.
 * Contains session information for cleanup operations
 * (e.g., cache invalidation, logging, analytics).
 *
 * @package Glueful\Events\Auth
 */
class SessionDestroyedEvent extends Event
{
    /**
     * @param string $accessToken The access token that was revoked
     * @param string|null $userUuid User UUID if available
     * @param string $reason Reason for session destruction
     * @param array $metadata Additional metadata
     */
    public function __construct(
        private readonly string $accessToken,
        private readonly ?string $userUuid = null,
        private readonly string $reason = 'logout',
        private readonly array $metadata = []
    ) {
    }

    /**
     * Get access token
     *
     * @return string Access token
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * Get user UUID
     *
     * @return string|null User UUID
     */
    public function getUserUuid(): ?string
    {
        return $this->userUuid;
    }

    /**
     * Get destruction reason
     *
     * @return string Reason (logout, expired, revoked, etc.)
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Get metadata
     *
     * @return array Metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Check if session was destroyed due to expiration
     *
     * @return bool True if expired
     */
    public function isExpired(): bool
    {
        return $this->reason === 'expired';
    }

    /**
     * Check if session was manually revoked
     *
     * @return bool True if revoked
     */
    public function isRevoked(): bool
    {
        return in_array($this->reason, ['revoked', 'admin_revoked', 'security_revoked']);
    }
}
