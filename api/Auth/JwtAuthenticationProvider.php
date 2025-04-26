<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;

/**
 * JWT Authentication Provider
 * 
 * Implements authentication using JWT tokens and the existing
 * authentication infrastructure in the Glueful framework.
 * 
 * This provider leverages the TokenManager and SessionCacheManager
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
        
        try {
            // Extract token from Authorization header
            $token = $this->extractTokenFromRequest($request);
            
            if (!$token) {
                $this->lastError = 'No authentication token provided';
                return null;
            }
            
            // Validate token and get session data
            $sessionData = SessionCacheManager::getSession($token);
            
            if (!$sessionData) {
                $this->lastError = 'Invalid or expired authentication token';
                return null;
            }
            
            // Store authentication info in request attributes for middleware
            $request->attributes->set('authenticated', true);
            $request->attributes->set('user_id', $sessionData['uuid'] ?? null);
            $request->attributes->set('user_data', $sessionData);
            
            return $sessionData;
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
        // First check if there's an explicit is_admin flag in the user data
        if (isset($userData['is_admin']) && $userData['is_admin'] === true) {
            error_log("Admin access granted through is_admin flag for user: " . ($userData['username'] ?? 'unknown'));
            return true;
        }
        
        // Also check for superuser role as before
        if (isset($userData['roles']) && is_array($userData['roles'])) {
            foreach ($userData['roles'] as $role) {
                if (isset($role['name']) && $role['name'] === 'superuser') {
                    return true;
                }
            }
        }
        
        error_log("Admin access denied for user: " . ($userData['username'] ?? 'unknown') . 
                  ", roles: " . (isset($userData['roles']) ? json_encode($userData['roles']) : 'none'));
        
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
    ): array
    {
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