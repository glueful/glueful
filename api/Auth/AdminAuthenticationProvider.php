<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Repository\UserRepository;
use Glueful\Repository\RoleRepository;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;

/**
 * Admin Authentication Provider
 *
 * Handles admin-specific authentication with enhanced security requirements.
 * Specifically designed for administration panel access.
 */
class AdminAuthenticationProvider implements AuthenticationProviderInterface
{
    private UserRepository $userRepository;
    private RoleRepository $roleRepository;
    private PasswordHasher $passwordHasher;
    private ?string $error = null;

    /**
     * Create a new admin authentication provider
     */
    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->roleRepository = new RoleRepository();
        $this->passwordHasher = new PasswordHasher();
    }

    /**
     * Authenticate an admin user from an HTTP request
     *
     * @param Request $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    public function authenticate(Request $request): ?array
    {
        $credentials = $this->extractCredentials($request);
        $auditLogger = AuditLogger::getInstance();

        if (!$credentials) {
            // Log failed authentication due to invalid request format
            $auditLogger->authEvent(
                'admin_auth_failed',
                null,
                [
                    'reason' => $this->error ?? 'Invalid credentials format',
                    'ip_address' => $request->getClientIp()
                ],
                AuditEvent::SEVERITY_WARNING
            );
            return null;
        }

        try {
            // Validate credentials
            $user = $this->authenticateWithCredentials(
                $credentials['username'],
                $credentials['password']
            );

            if (!$user) {
                // Log failed authentication with invalid credentials
                $auditLogger->authEvent(
                    'admin_auth_failed',
                    null,
                    [
                        'username' => $credentials['username'],
                        'reason' => $this->error ?? 'Invalid credentials',
                        'ip_address' => $request->getClientIp()
                    ],
                    AuditEvent::SEVERITY_WARNING
                );
                error_log("Admin auth failed: Invalid credentials for user {$credentials['username']}");
                return null;
            }

            // Verify user has superuser role
            if (!$this->roleRepository->userHasRole($user['uuid'], 'superuser')) {
                $this->error = "Insufficient privileges";
                // Log failed authentication due to insufficient privileges
                $auditLogger->authEvent(
                    'admin_auth_failed',
                    $user['uuid'],
                    [
                        'username' => $credentials['username'],
                        'reason' => 'Insufficient privileges - missing superuser role',
                        'ip_address' => $request->getClientIp()
                    ],
                    AuditEvent::SEVERITY_WARNING
                );
                error_log("Admin auth failed: User {$credentials['username']} lacks superuser role");
                return null;
            }

            // Create admin session
            $user['is_admin'] = true;
            $sessionData = TokenManager::createUserSession($user, 'admin');

            if (empty($sessionData)) {
                $this->error = "Failed to create admin session";
                // Log failed authentication due to session creation issue
                $auditLogger->authEvent(
                    'admin_auth_failed',
                    $user['uuid'],
                    [
                        'username' => $credentials['username'],
                        'reason' => 'Failed to create admin session',
                        'ip_address' => $request->getClientIp()
                    ],
                    AuditEvent::SEVERITY_ERROR
                );
                error_log("Admin auth failed: Could not create session for user {$credentials['username']}");
                return null;
            }

            // Add admin flag to user data
            $sessionData['user']['is_admin'] = true;

            // Log successful admin authentication
            $auditLogger->authEvent(
                'admin_auth_success',
                $user['uuid'],
                [
                    'username' => $credentials['username'],
                    'ip_address' => $request->getClientIp(),
                    'session_id' => $sessionData['session_id'] ?? null
                ]
            );

            // Return user data in the same format as regular login
            return $sessionData;
        } catch (\Exception $e) {
            $this->error = "Admin authentication error: " . $e->getMessage();
            // Log admin authentication exception
            $auditLogger->authEvent(
                'admin_auth_error',
                $credentials['username'] ?? null,
                [
                    'error' => $e->getMessage(),
                    'ip_address' => $request->getClientIp()
                ],
                AuditEvent::SEVERITY_ERROR
            );
            error_log($this->error);
            return null;
        }
    }

    /**
     * Extract credentials from the request
     *
     * @param Request $request The HTTP request
     * @return array|null Credentials or null if invalid
     */
    private function extractCredentials(Request $request): ?array
    {
        $content = $request->getContent();
        $data = json_decode($content, true);

        if (!is_array($data)) {
            $this->error = "Invalid request format";
            return null;
        }

        if (empty($data['username']) || empty($data['password'])) {
            $this->error = "Username and password are required";
            return null;
        }

        // Check if username is an email
        if (filter_var($data['username'], FILTER_VALIDATE_EMAIL)) {
            $this->error = "Email login not supported for admin authentication";
            return null;
        }

        return [
            'username' => $data['username'],
            'password' => $data['password']
        ];
    }

    /**
     * Authenticate with username and password
     *
     * @param string $username Username
     * @param string $password Password
     * @return array|null User data if authenticated, null otherwise
     */
    private function authenticateWithCredentials(string $username, string $password): ?array
    {
        // Get user by username
        $user = $this->userRepository->findByUsername($username);
        $userProfile = $this->userRepository->getProfile($user['uuid']);

        if (!$user) {
            $this->error = "User not found";
            return null;
        }

        // Verify password
        if (!$this->passwordHasher->verify($password, $user['password'])) {
            error_log("Admin auth failed: Password mismatch for user {$username}");
            $this->error = "Invalid credentials";
            return null;
        }

        // Get user roles
        $roles = $this->roleRepository->getUserRoles($user['uuid']);
        $user['roles'] = array_column($roles, 'name');

        // Add profile info if missing
        if (!isset($user['profile'])) {
            $user['profile'] = [
                'first_name' => $userProfile['first_name'] ?? null,
                'last_name' => $userProfile['last_name'] ?? null,
                'photo_uuid' => $userProfile['photo_uuid'] ?? null,
                'photo_url' => $userProfile['photo_url'] ?? null
            ];

            // Remove individual profile fields if they were moved to the profile object
            foreach (['first_name', 'last_name', 'photo_uuid', 'photo_url'] as $field) {
                if (isset($user[$field])) {
                    unset($user[$field]);
                }
            }
        }

        // Record last login time
        $user['last_login'] = date('Y-m-d H:i:s');

        // Return user data without password
        unset($user['password']);
        return $user;
    }

    /**
     * Check if a user has admin privileges
     *
     * @param array $userData User data
     * @return bool True if user is an admin
     */
    public function isAdmin(array $userData): bool
    {
        return !empty($userData['is_admin']);
    }

    /**
     * Get the current authentication error
     *
     * @return string|null Error message or null if no error
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Validate a token
     *
     * Checks if a token is valid according to admin provider rules.
     *
     * @param string $token The token to validate
     * @return bool True if token is valid, false otherwise
     */
    public function validateToken(string $token): bool
    {
        $auditLogger = AuditLogger::getInstance();
        $userId = null;

        try {
            // For admin tokens, we use the TokenManager validateAccessToken method
            $isValid = TokenManager::validateAccessToken($token, 'admin');

            if (!$isValid) {
                $this->error = "Invalid admin token";

                // Log invalid admin token validation
                $auditLogger->authEvent(
                    'admin_token_invalid',
                    null,
                    [
                        'reason' => 'Token validation failed'
                    ],
                    AuditEvent::SEVERITY_WARNING
                );

                return false;
            }

            // Verify this is an admin token by checking the payload
            $payload = JWTService::decode($token);
            $userId = $payload['uuid'] ?? null;

            // Log the token validation result
            if (!empty($payload) && !empty($payload['is_admin'])) {
                $auditLogger->authEvent(
                    'admin_token_valid',
                    $userId,
                    [
                        'session_id' => $payload['session_id'] ?? null
                    ]
                );
                return true;
            } else {
                $auditLogger->authEvent(
                    'admin_token_invalid',
                    $userId,
                    [
                        'reason' => 'Token missing admin flag'
                    ],
                    AuditEvent::SEVERITY_WARNING
                );
                return false;
            }
        } catch (\Exception $e) {
            $this->error = "Token validation error: " . $e->getMessage();

            // Log token validation error
            $auditLogger->authEvent(
                'admin_token_error',
                $userId,
                [
                    'error' => $e->getMessage()
                ],
                AuditEvent::SEVERITY_ERROR
            );

            return false;
        }
    }

    /**
     * Check if this provider can handle a given token
     *
     * @param string $token The token to check
     * @return bool True if this provider can validate this token
     */
    public function canHandleToken(string $token): bool
    {
        try {
            // Attempt to decode token without verification
            $payload = JWTService::decode($token);

            $canHandle = !empty($payload) && !empty($payload['is_admin']);

            // Only log this if debugging is required - too noisy for regular operation
            if (defined('AUDIT_LOG_TOKEN_CHECKS') && constant('AUDIT_LOG_TOKEN_CHECKS')) {
                $auditLogger = AuditLogger::getInstance();
                $auditLogger->authEvent(
                    'admin_token_check',
                    $payload['uuid'] ?? null,
                    [
                        'can_handle' => $canHandle ? 'yes' : 'no',
                        'session_id' => $payload['session_id'] ?? null
                    ],
                    AuditEvent::SEVERITY_INFO
                );
            }

            // Check if it has admin claim
            return $canHandle;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate authentication tokens
     *
     * Creates access and refresh tokens for an admin user.
     *
     * @param array $userData User data to encode in tokens
     * @param int|null $accessTokenLifetime Access token lifetime in seconds
     * @param int|null $refreshTokenLifetime Refresh token lifetime in seconds
     * @return array Token pair with access_token and refresh_token
     */
    public function generateTokens(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array {
        // Add admin flag to user data
        $userData['is_admin'] = true;

        // Use TokenManager's generateTokenPair method instead
        $tokenPair = TokenManager::generateTokenPair(
            $userData,
            $accessTokenLifetime,
            $refreshTokenLifetime
        );

        // Log token generation
        $auditLogger = AuditLogger::getInstance();
        $auditLogger->authEvent(
            'admin_tokens_generated',
            $userData['uuid'] ?? null,
            [
                'username' => $userData['username'] ?? null,
                'session_id' => $tokenPair['session_id'] ?? null
            ]
        );

        return $tokenPair;
    }

    /**
     * Refresh authentication tokens
     *
     * Generates new token pair using refresh token.
     *
     * @param string $refreshToken Current refresh token
     * @param array $sessionData Session data associated with the refresh token
     * @return array|null New token pair or null if invalid
     */
    public function refreshTokens(string $refreshToken, array $sessionData): ?array
    {
        $auditLogger = AuditLogger::getInstance();
        $userId = $sessionData['uuid'] ?? null;

        try {
            // We need to validate that this is an admin refresh token
            if (empty($sessionData) || empty($sessionData['uuid'])) {
                $this->error = "Invalid refresh token data";

                // Log invalid refresh token data
                $auditLogger->authEvent(
                    'admin_token_refresh_failed',
                    null,
                    [
                        'reason' => 'Invalid refresh token data'
                    ],
                    AuditEvent::SEVERITY_WARNING
                );

                return null;
            }

            // Get the user data
            $user = $this->userRepository->findByUUID($sessionData['uuid']);

            if (!$user) {
                $this->error = "User not found";

                // Log user not found during token refresh
                $auditLogger->authEvent(
                    'admin_token_refresh_failed',
                    $userId,
                    [
                        'reason' => 'User not found'
                    ],
                    AuditEvent::SEVERITY_WARNING
                );

                return null;
            }

            // Verify user still has superuser role
            if (!$this->roleRepository->userHasRole($user['uuid'], 'superuser')) {
                $this->error = "Insufficient privileges";

                // Log insufficient privileges for token refresh
                $auditLogger->authEvent(
                    'admin_token_refresh_failed',
                    $userId,
                    [
                        'username' => $user['username'] ?? null,
                        'reason' => 'Insufficient privileges - missing superuser role'
                    ],
                    AuditEvent::SEVERITY_WARNING
                );

                return null;
            }

            // Use TokenManager to refresh the tokens
            $user['is_admin'] = true;
            $newTokens = TokenManager::refreshTokens($refreshToken, 'admin');

            if ($newTokens) {
                // Log successful token refresh
                $auditLogger->authEvent(
                    'admin_token_refreshed',
                    $userId,
                    [
                        'username' => $user['username'] ?? null,
                        'session_id' => $newTokens['session_id'] ?? null
                    ]
                );
            }

            return $newTokens;
        } catch (\Exception $e) {
            $this->error = "Token refresh error: " . $e->getMessage();

            // Log token refresh error
            $auditLogger->authEvent(
                'admin_token_refresh_error',
                $userId,
                [
                    'error' => $e->getMessage()
                ],
                AuditEvent::SEVERITY_ERROR
            );

            return null;
        }
    }
}
