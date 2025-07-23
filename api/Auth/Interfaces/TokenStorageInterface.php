<?php

namespace Glueful\Auth\Interfaces;

/**
 * Token Storage Interface
 *
 * Defines the contract for storing and managing authentication tokens
 * across multiple storage layers (database, cache, etc.)
 */
interface TokenStorageInterface
{
    /**
     * Store a new session with tokens in both database and cache
     *
     * @param array $sessionData Session information including user data
     * @param array $tokens Token pair (access_token, refresh_token, expires_in, token_type)
     * @return bool True on success, false on failure
     */
    public function storeSession(array $sessionData, array $tokens): bool;

    /**
     * Update existing session with new tokens
     *
     * @param string $sessionIdentifier Session ID or refresh token to identify session
     * @param array $newTokens New token pair
     * @return bool True on success, false on failure
     */
    public function updateSessionTokens(string $sessionIdentifier, array $newTokens): bool;

    /**
     * Retrieve session data by access token
     *
     * @param string $accessToken Access token to look up
     * @return array|null Session data or null if not found
     */
    public function getSessionByAccessToken(string $accessToken): ?array;

    /**
     * Retrieve session data by refresh token
     *
     * @param string $refreshToken Refresh token to look up
     * @return array|null Session data or null if not found
     */
    public function getSessionByRefreshToken(string $refreshToken): ?array;

    /**
     * Revoke a session and invalidate all its tokens
     *
     * @param string $sessionIdentifier Session ID or token to identify session
     * @return bool True on success, false on failure
     */
    public function revokeSession(string $sessionIdentifier): bool;

    /**
     * Revoke all sessions for a specific user
     *
     * @param string $userUuid User UUID
     * @return bool True on success, false on failure
     */
    public function revokeAllUserSessions(string $userUuid): bool;

    /**
     * Clean up expired sessions and tokens
     *
     * @return int Number of sessions cleaned up
     */
    public function cleanupExpiredSessions(): int;

    /**
     * Validate that both storage layers are synchronized
     *
     * @param string $sessionIdentifier Session to validate
     * @return bool True if synchronized, false if inconsistent
     */
    public function validateStorageConsistency(string $sessionIdentifier): bool;

    /**
     * Get storage layer health status
     *
     * @return array Health status of database and cache layers
     */
    public function getStorageHealth(): array;
}
