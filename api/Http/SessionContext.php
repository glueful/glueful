<?php

declare(strict_types=1);

namespace Glueful\Http;

/**
 * Session Context Service
 *
 * Provides abstracted access to session data, eliminating direct $_SESSION usage.
 * All session-related operations should go through this service.
 *
 * @package Glueful\Http
 */
class SessionContext
{
    private bool $started = false;
    private array $sessionData = [];

    /**
     * Start session if not already started
     */
    private function ensureStarted(): void
    {
        if (!$this->started) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            $this->sessionData = &$_SESSION;
            $this->started = true;
        }
    }

    /**
     * Get session value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $this->ensureStarted();
        return $this->sessionData[$key] ?? $default;
    }

    /**
     * Set session value
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function set(string $key, $value): void
    {
        $this->ensureStarted();
        $this->sessionData[$key] = $value;
    }

    /**
     * Check if session key exists
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        $this->ensureStarted();
        return isset($this->sessionData[$key]);
    }

    /**
     * Remove session value
     *
     * @param string $key
     * @return void
     */
    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($this->sessionData[$key]);
    }

    /**
     * Clear all session data
     *
     * @return void
     */
    public function clear(): void
    {
        $this->ensureStarted();
        $this->sessionData = [];
    }

    /**
     * Regenerate session ID
     *
     * @param bool $deleteOldSession
     * @return bool
     */
    public function regenerateId(bool $deleteOldSession = true): bool
    {
        $this->ensureStarted();
        return session_regenerate_id($deleteOldSession);
    }

    /**
     * Get session ID
     *
     * @return string
     */
    public function getId(): string
    {
        $this->ensureStarted();
        return session_id();
    }

    /**
     * Set session ID
     *
     * @param string $id
     * @return void
     */
    public function setId(string $id): void
    {
        if ($this->started) {
            throw new \RuntimeException('Cannot change session ID after session has started');
        }
        session_id($id);
    }

    /**
     * Get user UUID from session
     *
     * @return string|null
     */
    public function getUserUuid(): ?string
    {
        return $this->get('user_uuid');
    }

    /**
     * Set user UUID in session
     *
     * @param string $uuid
     * @return void
     */
    public function setUserUuid(string $uuid): void
    {
        $this->set('user_uuid', $uuid);
    }

    /**
     * Get user ID from session
     *
     * @return int|null
     */
    public function getUserId(): ?int
    {
        $userId = $this->get('user_id');
        return $userId !== null ? (int)$userId : null;
    }

    /**
     * Set user ID in session
     *
     * @param int $userId
     * @return void
     */
    public function setUserId(int $userId): void
    {
        $this->set('user_id', $userId);
    }

    /**
     * Check if user is authenticated
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->has('user_uuid') || $this->has('user_id');
    }

    /**
     * Clear authentication data
     *
     * @return void
     */
    public function clearAuth(): void
    {
        $this->remove('user_uuid');
        $this->remove('user_id');
        $this->remove('user');
    }

    /**
     * Get OAuth state
     *
     * @param string $provider
     * @return string|null
     */
    public function getOAuthState(string $provider): ?string
    {
        return $this->get($provider . '_oauth_state');
    }

    /**
     * Set OAuth state
     *
     * @param string $provider
     * @param string $state
     * @return void
     */
    public function setOAuthState(string $provider, string $state): void
    {
        $this->set($provider . '_oauth_state', $state);
    }

    /**
     * Remove OAuth state
     *
     * @param string $provider
     * @return void
     */
    public function removeOAuthState(string $provider): void
    {
        $this->remove($provider . '_oauth_state');
    }

    /**
     * Get all session data (for debugging)
     *
     * @return array
     */
    public function all(): array
    {
        $this->ensureStarted();
        return $this->sessionData;
    }

    /**
     * Destroy session
     *
     * @return bool
     */
    public function destroy(): bool
    {
        $this->ensureStarted();
        $this->sessionData = [];
        return session_destroy();
    }
}
