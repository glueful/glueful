<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Repository\UserRepository;

/**
 * API Key Authentication Provider
 *
 * Implements authentication using API keys stored in the database.
 * This demonstrates how different authentication methods can be implemented
 * using the same standardized interface.
 */
class ApiKeyAuthenticationProvider implements AuthenticationProviderInterface
{
    /** @var string|null Last authentication error message */
    private ?string $lastError = null;

    /** @var UserRepository User repository for looking up API keys */
    private UserRepository $userRepository;

    /**
     * Create a new API key authentication provider
     */
    public function __construct()
    {
        $this->userRepository = new UserRepository();
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(Request $request): ?array
    {
        $this->lastError = null;

        try {
            // Extract API key from request
            $apiKey = $this->extractApiKeyFromRequest($request);

            if (!$apiKey) {
                // Silently fail with a less alarming message so other auth methods can be tried
                $this->lastError = 'API key not found in request';
                return null;
            }

            // Validate the API key and get associated user
            $userData = $this->userRepository->findByApiKey($apiKey);

            if (!$userData) {
                $this->lastError = 'Invalid API key';
                return null;
            }

            // Check if the API key is still valid
            if ($userData['api_key_expires_at'] && strtotime($userData['api_key_expires_at']) < time()) {
                $this->lastError = 'Expired API key';
                return null;
            }

            // Store authentication info in request attributes
            $request->attributes->set('authenticated', true);
            $request->attributes->set('user_id', $userData['uuid'] ?? null);
            $request->attributes->set('user_data', $userData);
            $request->attributes->set('auth_method', 'api_key');

            return $userData;
        } catch (\Throwable $e) {
            $this->lastError = 'Authentication error: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isAdmin(array $userData): bool
    {
        // Check if user has the superuser role
        if (!isset($userData['roles']) || !is_array($userData['roles'])) {
            return false;
        }

        foreach ($userData['roles'] as $role) {
            if (isset($role['name']) && $role['name'] === 'superuser') {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Extract API key from request
     *
     * @param Request $request The HTTP request
     * @return string|null The API key or null if not found
     */
    private function extractApiKeyFromRequest(Request $request): ?string
    {
        // Check for API key in the X-API-Key header (preferred)
        $apiKey = $request->headers->get('X-API-Key');
        if ($apiKey) {
            return $apiKey;
        }

        // Check for API key in the query string
        $apiKey = $request->query->get('api_key');
        if ($apiKey) {
            return $apiKey;
        }

        // Check for API key in the Authorization header with "ApiKey" prefix
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && strpos($authHeader, 'ApiKey ') === 0) {
            return substr($authHeader, 7);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function validateToken(string $token): bool
    {
        try {
            // Check if the API key exists and is valid in the database
            $userData = $this->userRepository->findByApiKey($token);

            if (!$userData) {
                $this->lastError = 'Invalid API key';
                return false;
            }

            // Check if the API key is still valid
            if ($userData['api_key_expires_at'] && strtotime($userData['api_key_expires_at']) < time()) {
                $this->lastError = 'Expired API key';
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->lastError = 'API key validation error: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function canHandleToken(string $token): bool
    {
        // API keys are typically alphanumeric strings with a specific length
        // This is a simple pattern match to determine if a token could be an API key
        return (bool) preg_match('/^[a-zA-Z0-9_\-]{16,64}$/', $token);
    }

    /**
     * {@inheritdoc}
     */
    public function generateTokens(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array {
        try {
            // For API Key authentication, we don't generate separate refresh tokens
            // Instead, we just return the API key as the access token
            $apiKey = $userData['api_key'] ?? '';

            if (empty($apiKey)) {
                $this->lastError = 'No API key available for user';
                return [
                    'access_token' => '',
                    'refresh_token' => '',
                    'expires_in' => 0
                ];
            }

            // Calculate expiration based on the API key expiration date in userData
            $expiresIn = 0;
            if (isset($userData['api_key_expires_at']) && !empty($userData['api_key_expires_at'])) {
                $expiresTimestamp = strtotime($userData['api_key_expires_at']);
                if ($expiresTimestamp !== false) {
                    $expiresIn = max(0, $expiresTimestamp - time());
                }
            }

            return [
                'access_token' => $apiKey,
                'refresh_token' => $apiKey, // Same as access token for API key auth
                'expires_in' => $expiresIn
            ];
        } catch (\Throwable $e) {
            $this->lastError = 'Token generation error: ' . $e->getMessage();
            return [
                'access_token' => '',
                'refresh_token' => '',
                'expires_in' => 0
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        try {
            // For API Key authentication, we don't have separate refresh tokens
            // We just verify if the API key is still valid

            $userData = $this->userRepository->findByApiKey($refreshToken);

            if (!$userData) {
                $this->lastError = 'Invalid API key';
                return null;
            }

            // Check if the API key has expired
            if (
                isset($userData['api_key_expires_at']) &&
                !empty($userData['api_key_expires_at']) &&
                strtotime($userData['api_key_expires_at']) < time()
            ) {
                $this->lastError = 'Expired API key';
                return null;
            }

            // For API keys, no new tokens are generated during refresh
            // We just return the same key with updated expiration time
            return $this->generateTokens($userData);
        } catch (\Throwable $e) {
            $this->lastError = 'Token refresh error: ' . $e->getMessage();
            return null;
        }
    }
}
