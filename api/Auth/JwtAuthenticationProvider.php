<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Permissions\Helpers\PermissionHelper;

/**
 * JWT Authentication Provider
 *
 * Implements authentication using JWT tokens and the existing
 * authentication infrastructure in the Glueful framework.
 *
 * This provider leverages the TokenManager and TokenStorageService
 * while providing a standardized interface for authentication.
 */
class JwtAuthenticationProvider implements AuthenticationProviderInterface
{
    /** @var string|null Last authentication error message */
    private ?string $lastError = null;

    /**
     * {@inheritdoc}
     */
    public function authenticate(Request $request): ?array
    {
        $this->lastError = null;
        $clientIp = $request->getClientIp();

        try {
            // Extract token from Authorization header
            $token = $this->extractTokenFromRequest($request);
            if (!$token) {
                $this->lastError = 'No authentication token provided';
                return null;
            }

            // Validate token and get session data using TokenStorageService
            $tokenStorage = new TokenStorageService();
            $sessionData = $tokenStorage->getSessionByAccessToken($token);
            if (!$sessionData) {
                $this->lastError = 'Invalid or expired authentication token';
                return null;
            }

            // Decode JWT token to get the full user data
            $payload = JWTService::decode($token);
            if (!$payload) {
                $this->lastError = 'Invalid JWT token payload';
                return null;
            }

            // Use the JWT payload as user data since it contains all user information
            $userData = $payload;
            $userData['session_uuid'] = $sessionData['uuid'];
            $userData['provider'] = $sessionData['provider'] ?? 'jwt';

            // Store authentication info in request attributes for middleware
            $request->attributes->set('authenticated', true);
            $request->attributes->set('user_id', $userData['uuid'] ?? null);
            $request->attributes->set('user_data', $userData);

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
        $user = $userData['user'] ?? $userData;

        // Fallback to is_admin flag if no UUID available
        if (!isset($user['uuid'])) {
            return !empty($user['is_admin']);
        }

        // Check if permission system is available
        if (!PermissionHelper::isAvailable()) {
            // Fall back to is_admin flag
            return !empty($user['is_admin']);
        }

        // Check if user has admin access using PermissionHelper
        $hasAdminAccess = PermissionHelper::canAccessAdmin(
            $user['uuid'],
            ['auth_check' => true, 'provider' => 'jwt']
        );

        // If permission check fails, fall back to is_admin flag as safety net
        if (!$hasAdminAccess && !empty($user['is_admin'])) {
            error_log("Admin permission check failed for user {$user['uuid']}, falling back to is_admin flag");
            return true;
        }

        return $hasAdminAccess;
    }

    /**
     * {@inheritdoc}
     */
    public function getError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Extract JWT token from request
     *
     * @param Request $request The HTTP request
     * @return string|null The token or null if not found
     */
    private function extractTokenFromRequest(Request $request): ?string
    {
        $authHeader = $request->headers->get('Authorization');

        if (!$authHeader) {
            return null;
        }

        // Remove 'Bearer ' prefix if present
        if (strpos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }

        return $authHeader;
    }

    /**
     * {@inheritdoc}
     */
    public function validateToken(string $token): bool
    {
        try {
            // Use JWTService to verify the token
            return JWTService::verify($token);
        } catch (\Throwable $e) {
            $this->lastError = 'Token validation error: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function canHandleToken(string $token): bool
    {
        try {
            // Check if the token is a valid JWT structure
            // JWT tokens consist of 3 parts separated by periods
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return false;
            }

            // Try to decode the header (first part)
            $headerJson = base64_decode(strtr($parts[0], '-_', '+/'));
            if (!$headerJson) {
                return false;
            }

            $header = json_decode($headerJson, true);
            // Check if it has typical JWT header fields
            return is_array($header) && isset($header['alg']) && isset($header['typ']);
        } catch (\Throwable $e) {
            return false;
        }
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
            // Use TokenManager to generate token pair
            return TokenManager::generateTokenPair(
                $userData,
                $accessTokenLifetime,
                $refreshTokenLifetime
            );
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
            // Verify that the refresh token matches the one in session data
            if (!isset($sessionData['refresh_token']) || $sessionData['refresh_token'] !== $refreshToken) {
                $this->lastError = 'Invalid refresh token';
                return null;
            }

            // Generate new token pair for existing session
            return $this->generateTokens($sessionData);
        } catch (\Throwable $e) {
            $this->lastError = 'Token refresh error: ' . $e->getMessage();
            return null;
        }
    }
}
