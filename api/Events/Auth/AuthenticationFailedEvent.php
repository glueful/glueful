<?php

declare(strict_types=1);

namespace Glueful\Events\Auth;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Authentication Failed Event
 *
 * Dispatched when authentication attempts fail.
 * Used for security monitoring, rate limiting, and audit logging.
 *
 * @package Glueful\Events\Auth
 */
class AuthenticationFailedEvent extends Event
{
    /**
     * @param string $username Attempted username/email
     * @param string $reason Failure reason
     * @param string|null $clientIp Client IP address
     * @param string|null $userAgent Client user agent
     * @param array $metadata Additional failure metadata
     */
    public function __construct(
        private readonly string $username,
        private readonly string $reason,
        private readonly ?string $clientIp = null,
        private readonly ?string $userAgent = null,
        private readonly array $metadata = []
    ) {
    }

    /**
     * Get attempted username
     *
     * @return string Username
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Get failure reason
     *
     * @return string Reason (invalid_credentials, user_disabled, etc.)
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * Get client IP address
     *
     * @return string|null Client IP
     */
    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    /**
     * Get client user agent
     *
     * @return string|null User agent
     */
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
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
     * Check if failure was due to invalid credentials
     *
     * @return bool True if invalid credentials
     */
    public function isInvalidCredentials(): bool
    {
        return $this->reason === 'invalid_credentials';
    }

    /**
     * Check if failure was due to disabled user
     *
     * @return bool True if user disabled
     */
    public function isUserDisabled(): bool
    {
        return in_array($this->reason, ['user_disabled', 'user_suspended', 'user_locked']);
    }

    /**
     * Check if this is a potential brute force attempt
     *
     * @return bool True if suspicious
     */
    public function isSuspicious(): bool
    {
        return $this->metadata['suspicious'] ?? false;
    }
}
