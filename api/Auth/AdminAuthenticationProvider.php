<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Repository\UserRepository;
use Glueful\Repository\RoleRepository;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;

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
        // Get request information for tracking
        $requestData = $request::createFromGlobals();

        // Get the audit logger
        $auditLogger = AuditLogger::getInstance();

        if (!$credentials) {
            // Authentication failed due to invalid request format
            $auditLogger->authEvent(
                'admin_login_failure',
                null,
                [
                    'reason' => 'invalid_request_format',
                    'error' => $this->error,
                    'ip_address' => $request->getClientIp()
                ],
                AuditEvent::SEVERITY_WARNING
            );
            return null;
        }

        try {
            // Skip logging login attempt to reduce audit log noise
            // We already log success/failure which is sufficient

            // Validate credentials
            $user = $this->authenticateWithCredentials(
                $credentials['username'],
                $credentials['password']
            );

            if (!$user) {
                error_log("Admin auth failed: Invalid credentials for user {$credentials['username']}");

                // Log failed admin login due to invalid credentials
                $auditLogger->authEvent(
                    'admin_login_failure',
                    null,
                    [
                        'username' => $credentials['username'],
                        'reason' => 'invalid_credentials',
                        'ip_address' => $requestData->getClientIp(),
                        'user_agent' => $requestData->headers->get('User-Agent')
                    ],
                    AuditEvent::SEVERITY_WARNING
                );
                return null;
            }

            // Verify user has superuser role - check from already fetched roles
            $hasSuperuserRole = false;
            foreach ($user['roles'] as $roleName) {
                if ($roleName === 'superuser') {
                    $hasSuperuserRole = true;
                    break;
                }
            }

            if (!$hasSuperuserRole) {
                $this->error = "Insufficient privileges";
                error_log("Admin auth failed: User {$credentials['username']} lacks superuser role");

                // Log failed admin login due to insufficient privileges
                $auditLogger->authEvent(
                    'admin_login_failure',
                    $user['uuid'],
                    [
                        'username' => $credentials['username'],
                        'reason' => 'insufficient_privileges',
                        'ip_address' => $requestData->getClientIp(),
                        'user_agent' => $requestData->headers->get('User-Agent')
                    ],
                    AuditEvent::SEVERITY_WARNING
                );
                return null;
            }

            // Create admin session
            $user['is_admin'] = true;
            $sessionData = TokenManager::createUserSession($user, 'admin');

            if (empty($sessionData)) {
                $this->error = "Failed to create admin session";
                error_log("Admin auth failed: Could not create session for user {$credentials['username']}");

                // Log failed admin login due to session creation failure
                $auditLogger->authEvent(
                    'admin_login_failure',
                    $user['uuid'],
                    [
                        'username' => $credentials['username'],
                        'reason' => 'session_creation_failure',
                        'ip_address' => $requestData->getClientIp(),
                        'user_agent' => $requestData->headers->get('User-Agent')
                    ],
                    AuditEvent::SEVERITY_ERROR
                );
                return null;
            }

            // Add admin flag to user data
            $sessionData['user']['is_admin'] = true;

            // Log successful admin login with session details
            $auditLogger->authEvent(
                'admin_login_success',
                $user['uuid'],
                [
                    'username' => $credentials['username'],
                    'ip_address' => $requestData->getClientIp(),
                    'user_agent' => $requestData->headers->get('User-Agent'),
                    'session_id' => $sessionData['user']['session_id'] ?? null,
                    'session_created' => true,
                    'provider' => 'admin'
                ],
                AuditEvent::SEVERITY_INFO
            );

            // Update user tracking fields in the database
            try {
                $clientIp = $requestData->getClientIp() ?? 'unknown';
                $userAgent = $requestData->headers->get('User-Agent') ?? 'unknown';
                $xForwardedFor = $requestData->headers->get('X-Forwarded-For') ?? null;

                $this->userRepository->update($user['uuid'], [
                    'ip_address' => $clientIp,
                    'user_agent' => substr($userAgent, 0, 512), // Limit to field size
                    'x_forwarded_for_ip_address' => $xForwardedFor ? substr($xForwardedFor, 0, 40) : null,
                    'last_login_date' => date('Y-m-d H:i:s')
                ]);
            } catch (\Exception $e) {
                // Log the error but don't fail authentication
                error_log("Failed to update admin user tracking fields: " . $e->getMessage());
            }

            // Return user data in the same format as regular login
            return $sessionData;
        } catch (\Exception $e) {
            $this->error = "Admin authentication error: " . $e->getMessage();
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
        // Get user with profile and roles in one optimized query
        $user = $this->userRepository->findByUsernameWithProfileAndRoles($username);

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
        try {
            // For admin tokens, we use the TokenManager validateAccessToken method
            $isValid = TokenManager::validateAccessToken($token, 'admin');

            if (!$isValid) {
                $this->error = "Invalid admin token";
                return false;
            }

            // Verify this is an admin token by checking the payload
            $payload = JWTService::decode($token);

            // Check if token has admin flag
            if (!empty($payload) && !empty($payload['is_admin'])) {
                return true;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            $this->error = "Token validation error: " . $e->getMessage();
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

            // Check if it has admin claim
            return $canHandle;
        } catch (\Exception) {
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
        try {
            // We need to validate that this is an admin refresh token
            if (empty($sessionData) || empty($sessionData['uuid'])) {
                $this->error = "Invalid refresh token data";
                return null;
            }

            // Get the user data
            $user = $this->userRepository->findByUUID($sessionData['uuid']);

            if (!$user) {
                $this->error = "User not found";
                return null;
            }

            // Get user roles and verify user still has superuser role
            $roles = $this->roleRepository->getUserRoles($user['uuid']);
            $roleNames = [];
            foreach ($roles as $role) {
                $roleNames[] = $role['role_name'] ?? $role['name'] ?? '';
            }
            $hasSuperuserRole = in_array('superuser', $roleNames);
            if (!$hasSuperuserRole) {
                $this->error = "Insufficient privileges";
                return null;
            }

            // Use TokenManager to refresh the tokens
            $user['is_admin'] = true;
            $newTokens = TokenManager::refreshTokens($refreshToken, 'admin');

            return $newTokens;
        } catch (\Exception $e) {
            $this->error = "Token refresh error: " . $e->getMessage();
            return null;
        }
    }
}
