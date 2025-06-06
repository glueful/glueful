<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;
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
        $auditLogger = AuditLogger::getInstance();
        $clientIp = $request->getClientIp();

        try {
            // Extract token from Authorization header
            $token = $this->extractTokenFromRequest($request);

            if (!$token) {
                $this->lastError = 'No authentication token provided';

                // Log authentication failure - no token
                $auditLogger->audit(
                    AuditEvent::CATEGORY_AUTH,
                    'token_validation_failure',
                    AuditEvent::SEVERITY_WARNING,
                    [
                        'reason' => 'no_token_provided',
                        'ip_address' => $clientIp,
                        'user_agent' => $request->headers->get('User-Agent'),
                        'uri' => $request->getRequestUri()
                    ]
                );

                return null;
            }

            // Validate token and get session data using TokenStorageService
            $tokenStorage = new TokenStorageService();
            $sessionData = $tokenStorage->getSessionByAccessToken($token);

            if (!$sessionData) {
                $this->lastError = 'Invalid or expired authentication token';

                // Log authentication failure - invalid token
                $auditLogger->audit(
                    AuditEvent::CATEGORY_AUTH,
                    'token_validation_failure',
                    AuditEvent::SEVERITY_WARNING,
                    [
                        'reason' => 'invalid_or_expired_token',
                        'ip_address' => $clientIp,
                        'user_agent' => $request->headers->get('User-Agent'),
                        'uri' => $request->getRequestUri()
                    ]
                );

                return null;
            }

            // Store authentication info in request attributes for middleware
            $request->attributes->set('authenticated', true);
            $request->attributes->set('user_id', $sessionData['uuid'] ?? null);
            $request->attributes->set('user_data', $sessionData);

            // Log successful authentication via token
            $auditLogger->audit(
                AuditEvent::CATEGORY_AUTH,
                'token_validation_success',
                AuditEvent::SEVERITY_INFO,
                [
                    'user_id' => $sessionData['uuid'] ?? null,
                    'username' => $sessionData['username'] ?? null,
                    'ip_address' => $clientIp,
                    'user_agent' => $request->headers->get('User-Agent'),
                    'uri' => $request->getRequestUri()
                ]
            );

            return $sessionData;
        } catch (\Throwable $e) {
            $this->lastError = 'Authentication error: ' . $e->getMessage();

            // Log authentication error - exception
            $auditLogger->audit(
                AuditEvent::CATEGORY_AUTH,
                'token_validation_error',
                AuditEvent::SEVERITY_ERROR,
                [
                    'reason' => 'exception',
                    'message' => $e->getMessage(),
                    'ip_address' => $clientIp,
                    'user_agent' => $request->headers->get('User-Agent'),
                    'uri' => $request->getRequestUri()
                ]
            );

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
        $auditLogger = AuditLogger::getInstance();
        $tokenData = null;

        // Extract payload from token for logging if possible
        try {
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
                if ($payloadJson) {
                    $tokenData = json_decode($payloadJson, true);
                }
            }
        } catch (\Throwable $e) {
            // Silently fail, this is just for logging purposes
            $tokenData = null;
        }

        try {
            // Use JWTService to verify the token
            $isValid = JWTService::verify($token);

            // Log the validation result
            $auditLogger->audit(
                AuditEvent::CATEGORY_AUTH,
                'token_standalone_validation',
                AuditEvent::SEVERITY_INFO,
                [
                    'result' => $isValid ? 'valid' : 'invalid',
                    'user_id' => $tokenData['sub'] ?? null,
                    'token_type' => $tokenData['type'] ?? 'unknown',
                    'token_expiry' => $tokenData['exp'] ?? null
                ]
            );

            return $isValid;
        } catch (\Throwable $e) {
            $this->lastError = 'Token validation error: ' . $e->getMessage();

            // Log validation error
            $auditLogger->audit(
                AuditEvent::CATEGORY_AUTH,
                'token_standalone_validation_error',
                AuditEvent::SEVERITY_ERROR,
                [
                    'error' => $e->getMessage(),
                    'user_id' => $tokenData['sub'] ?? null,
                    'token_type' => $tokenData['type'] ?? 'unknown'
                ]
            );

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function canHandleToken(string $token): bool
    {
        $auditLogger = AuditLogger::getInstance();
        $result = false;
        $reason = 'unknown';

        try {
            // Check if the token is a valid JWT structure
            // JWT tokens consist of 3 parts separated by periods
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                $reason = 'invalid_format';
                $result = false;
                return false;
            }

            // Try to decode the header (first part)
            $headerJson = base64_decode(strtr($parts[0], '-_', '+/'));
            if (!$headerJson) {
                $reason = 'invalid_header_encoding';
                $result = false;
                return false;
            }

            $header = json_decode($headerJson, true);
            // Check if it has typical JWT header fields
            $result = is_array($header) && isset($header['alg']) && isset($header['typ']);

            if (!$result) {
                $reason = 'missing_jwt_fields';
            }

            return $result;
        } catch (\Throwable $e) {
            $reason = 'exception: ' . $e->getMessage();
            $result = false;
            return false;
        } finally {
            // Log token type check regardless of outcome
            $auditLogger->audit(
                AuditEvent::CATEGORY_AUTH,
                'token_format_check',
                AuditEvent::SEVERITY_INFO,
                [
                    'result' => $result ? 'valid_jwt_format' : 'invalid_jwt_format',
                    'reason' => $reason,
                    'partial_token' => substr($token, 0, 10) . '...' // Log only a small prefix for identification
                ]
            );
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
