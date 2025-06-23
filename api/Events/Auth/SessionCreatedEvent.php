<?php

declare(strict_types=1);

namespace Glueful\Events\Auth;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Session Created Event
 *
 * Dispatched when a new user session is created during authentication.
 * Contains session data and tokens for listeners that need to respond
 * to new session creation (e.g., logging, analytics, cache warming).
 *
 * @package Glueful\Events\Auth
 */
class SessionCreatedEvent extends Event
{
    /**
     * @param array $sessionData Session data (uuid, username, email, etc.)
     * @param array $tokens Access and refresh tokens
     * @param array $metadata Additional session metadata
     */
    public function __construct(
        private readonly array $sessionData,
        private readonly array $tokens,
        private readonly array $metadata = []
    ) {
    }

    /**
     * Get session data
     *
     * @return array Session data
     */
    public function getSessionData(): array
    {
        return $this->sessionData;
    }

    /**
     * Get user UUID from session
     *
     * @return string|null User UUID
     */
    public function getUserUuid(): ?string
    {
        return $this->sessionData['uuid'] ?? null;
    }

    /**
     * Get username from session
     *
     * @return string|null Username
     */
    public function getUsername(): ?string
    {
        return $this->sessionData['username'] ?? null;
    }

    /**
     * Get tokens
     *
     * @return array Tokens array
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    /**
     * Get access token
     *
     * @return string|null Access token
     */
    public function getAccessToken(): ?string
    {
        return $this->tokens['access_token'] ?? null;
    }

    /**
     * Get refresh token
     *
     * @return string|null Refresh token
     */
    public function getRefreshToken(): ?string
    {
        return $this->tokens['refresh_token'] ?? null;
    }

    /**
     * Get session metadata
     *
     * @return array Session metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get specific metadata value
     *
     * @param string $key Metadata key
     * @param mixed $default Default value
     * @return mixed Metadata value
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }
}
