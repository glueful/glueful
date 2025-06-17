<?php

declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Repository\UserRepository;
use Glueful\DTOs\{PasswordDTO};
use Glueful\Validation\Validator;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Auth\Interfaces\TokenStorageInterface;

/**
 * Authentication Service
 *
 * Provides high-level authentication functionality:
 * - User login/logout
 * - Credential validation
 * - Session management
 * - Token validation
 *
 * Coordinates between repositories and managers to implement
 * authentication flows in a clean, maintainable way.
 *
 * Now leverages the AuthenticationManager for request authentication
 * while maintaining backward compatibility.
 */
class AuthenticationService
{
    private UserRepository $userRepository;
    private Validator $validator;
    private PasswordHasher $passwordHasher;
    private AuthenticationManager $authManager;
    private TokenStorageInterface $tokenStorage;

    /**
     * Constructor
     *
     * Initializes service dependencies.
     */
    public function __construct(?TokenStorageInterface $tokenStorage = null)
    {
        $this->userRepository = new UserRepository();
        $this->validator = new Validator();
        $this->passwordHasher = new PasswordHasher();
        $this->tokenStorage = $tokenStorage ?? new TokenStorageService();

        // Ensure authentication system is initialized
        AuthBootstrap::initialize();

        // Get the authentication manager instance
        $this->authManager = AuthBootstrap::getManager();
    }

    /**
     * Authenticate user
     *
     * Validates credentials and creates user session.
     * Can work with different authentication providers depending on
     * the format of credentials provided.
     *
     * @param array $credentials User credentials
     * @param string|null $providerName Optional name of the provider to use
     * @return array|null Authentication result or null if failed
     */
    public function authenticate(array $credentials, ?string $providerName = null): ?array
    {
        // If a specific provider is requested, try to use it
        if ($providerName) {
            $provider = $this->authManager->getProvider($providerName);
            if (!$provider) {
                // Provider not found
                return null;
            }

            // For token-based providers, convert credentials to a request
            if (isset($credentials['token'])) {
                $request = new Request();
                $request->headers->set('Authorization', 'Bearer ' . $credentials['token']);
                return $this->authManager->authenticateWithProvider($providerName, $request);
            }

            // For API key providers
            if (isset($credentials['api_key'])) {
                $request = new Request();
                $request->headers->set('X-API-Key', $credentials['api_key']);
                return $this->authManager->authenticateWithProvider($providerName, $request);
            }
        }

        // Default flow for username/password authentication
        // Validate required fields
        if (!$this->validateCredentials($credentials)) {
            return null;
        }

        // Process credentials based on type (username or email) using optimized query
        $user = null;

        if (isset($credentials['username'])) {
            if (filter_var($credentials['username'], FILTER_VALIDATE_EMAIL)) {
                // For email login
                $user = $this->userRepository->findByEmail($credentials['username']);
            } else {
                // For username login
                $user = $this->userRepository->findByUsername($credentials['username']);
            }
        }

        // If user not found or is an array of error messages
        if (!$user) {
            return null;
        }

        // Validate password
        $passwordDTO = new PasswordDTO();
        $passwordDTO->password = $credentials['password'];

        if (!$this->validator->validate($passwordDTO)) {
            return null;
        }

        // Verify password against hash
        if (!isset($user['password']) || !$this->passwordHasher->verify($credentials['password'], $user['password'])) {
            return null;
        }

        // Format user data and get profile
        $userData = $this->formatUserData($user);
        $userData['profile'] = $this->userRepository->getProfile($user['uuid']) ?? null;
        $userData['roles'] = []; // Roles managed by RBAC extension
        $userData['last_login'] = date('Y-m-d H:i:s');

        // Pass through remember_me preference from credentials
        $userData['remember_me'] = $credentials['remember_me'] ?? false;

        // Update user tracking fields in the database
        try {
            // Get request information for tracking
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            $clientIp = $request->getClientIp() ?? 'unknown';
            $userAgent = $request->headers->get('User-Agent') ?? 'unknown';
            $xForwardedFor = $request->headers->get('X-Forwarded-For') ?? null;
            // Update user record with tracking information
            $userRepo = new UserRepository();
            $userRepo->update($userData['uuid'], [
                'ip_address' => $clientIp,
                'user_agent' => substr($userAgent, 0, 512), // Limit to field size
                'x_forwarded_for_ip_address' => $xForwardedFor ? substr($xForwardedFor, 0, 40) : null,
                'last_login_date' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            // Log the error but don't fail authentication
            error_log("Failed to update user tracking fields: " . $e->getMessage());
        }

        // Add any custom provider preference from credentials
        $preferredProvider = $providerName ?? ($credentials['provider'] ?? 'jwt');

        // Create user session with the appropriate provider
        $userSession = TokenManager::createUserSession($userData, $preferredProvider);

        // Return authentication result
        return $userSession;
    }

    /**
     * Terminate user session
     *
     * Logs out user and invalidates tokens.
     *
     * @param string $token Authentication token
     * @return bool Success status
     */
    public function terminateSession(string $token): bool
    {
        if (!$token) {
            return false;
        }
        // SessionCacheManager::destroySession() handles token revocation
        return SessionCacheManager::destroySession($token);
    }

    /**
     * Validate access token
     *
     * Checks if token is valid and session exists.
     *
     * @param string $token Authentication token
     * @return array|null Session data if valid
     */
    public static function validateAccessToken(string $token): ?array
    {
        if (!TokenManager::validateAccessToken($token)) {
            return null;
        }

        return SessionCacheManager::getSession($token);
    }

    /**
     * Validate login credentials
     *
     * Ensures required fields are present.
     *
     * @param array $credentials User credentials
     * @return bool Validity status
     */
    public function validateCredentials(array $credentials): bool
    {
        return (
            (isset($credentials['username']) || isset($credentials['email'])) &&
            isset($credentials['password'])
        );
    }

    /**
     * Extract token from request
     *
     * Gets authentication token from request.
     *
     * @param Request|null $request The request object
     * @return string|null Authentication token
     */
    public static function extractTokenFromRequest(?Request $request = null): ?string
    {
        // If no request is provided, use TokenManager directly
        if ($request === null) {
            return TokenManager::extractTokenFromRequest();
        }

        // Extract token from Authorization header
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
     * Format user data for session
     *
     * Prepares user data for storage in session.
     *
     * @param array $user Raw user data
     * @return array Formatted user data
     */
    private function formatUserData(array $user): array
    {
        // Remove password field
        unset($user['password']);

        // Get additional user data if needed
        // For now, return the basic user data
        return $user;
    }

    /**
     * Refresh user permissions
     *
     * Updates permissions in the user session and generates a new token.
     * Used when user permissions change during an active session.
     * Works with any authentication provider.
     *
     * @param string $token Current authentication token
     * @return array|null Response with new token and updated permissions or null if failed
     */
    public function refreshPermissions(string $token): ?array
    {
        // Get current session
        $session = SessionCacheManager::getSession($token);
        if (!$session) {
            return null;
        }

        // Get user UUID from session
        $userUuid = $session['user']['uuid'] ?? null;
        if (!$userUuid) {
            return null;
        }

        // Note: Role functionality moved to RBAC extension
        $userRoles = []; // Use RBAC extension APIs for role management

        // Update session with new roles
        $session['user']['roles'] = $userRoles;

        // Identify which provider was used for this token
        $provider = $session['provider'] ?? 'jwt';

        // Use appropriate provider to generate a new token
        $authProvider = $this->authManager->getProvider($provider);

        if ($authProvider) {
            // Generate new token using the same provider that created the original token
            $tokenLifetime = (int)config('session.access_token_lifetime');

            // Create a minimal user data array with permissions
            $userData = [
                'uuid' => $userUuid,
                'roles' => $userRoles,
                // Copy any other essential user data from the session
                'username' => $session['user']['username'] ?? null,
                'email' => $session['user']['email'] ?? null
            ];

            // Generate new token pair
            $tokens = $authProvider->generateTokens($userData, $tokenLifetime);
            $newToken = $tokens['access_token'];

            // Update session storage with new token
            SessionCacheManager::updateSession($token, $session, $newToken, $provider);

            return [
                'token' => $newToken,
                'permissions' => $userRoles
            ];
        } else {
            // Fall back to default JWT method if provider not found
            $tokenLifetime = (int)config('session.access_token_lifetime');
            $newToken = JWTService::generate($session, $tokenLifetime);

            // Update session storage
            SessionCacheManager::updateSession($token, $session, $newToken);

            return [
                'token' => $newToken,
                'permissions' => $userRoles
            ];
        }
    }

    /**
     * Update user password
     *
     * Changes user password and invalidates existing sessions.
     *
     * @param string $identifier User's email or UUID
     * @param string $password New password (plaintext)
     * @param string|null $identifierType Optional type specifier ('email' or 'uuid')
     * @return bool Success status
     */
    public function updatePassword(string $identifier, string $password, ?string $identifierType = null): bool
    {
        // Validate password
        $passwordDTO = new PasswordDTO();
        $passwordDTO->password = $password;

        if (!$this->validator->validate($passwordDTO)) {
            return false;
        }

        // Determine identifier type automatically if not specified
        if ($identifierType === null) {
            $identifierType = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'uuid';
        }

        // Hash password
        $hashedPassword = $this->passwordHasher->hash($password);

        // Update password in database using the new method
        return $this->userRepository->setNewPassword($identifier, $hashedPassword, $identifierType);
    }

    /**
     * Check if user exists
     *
     * Verifies if a user exists in the system by identifier.
     * This method is useful for validating user existence without
     * revealing which specific criteria failed during operations
     * like password reset or account recovery.
     *
     * @param string $identifier User's email or UUID to check
     * @param string|null $identifierType Optional type specifier ('email' or 'uuid')
     * @return bool True if user exists, false otherwise
     */
    public function userExists(string $identifier, ?string $identifierType = null): bool
    {
        // Determine identifier type automatically if not specified
        if ($identifierType === null) {
            $identifierType = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'uuid';
        }

        // Attempt to find the user based on identifier type
        $user = $identifierType === 'email'
            ? $this->userRepository->findByEmail($identifier)
            : $this->userRepository->findByUUID($identifier);

        // Return true if user was found and is properly formatted
        return !empty($user) && is_array($user);
    }

    /**
     * Refresh authentication tokens
     *
     * Generates a new token pair using a refresh token.
     * This method:
     * 1. Validates the refresh token
     * 2. Retrieves the associated user session
     * 3. Generates a new token pair
     * 4. Updates the session with the new tokens
     * 5. Returns the new token pair to the client
     *
     * Security note:
     * - The refresh token is validated against the database
     * - Both old tokens are invalidated when a new pair is generated
     * - Sessions with explicitly revoked tokens cannot be refreshed
     *
     * @param string $refreshToken Current refresh token
     * @return array|null New token pair or null if refresh token is invalid
     */
    public function refreshTokens(string $refreshToken): ?array
    {
        // Get new token pair from TokenManager
        $tokens = TokenManager::refreshTokens($refreshToken);

        if (!$tokens) {
            return null;
        }

        // Get user data from refresh token
        $userData = $this->getUserDataFromRefreshToken($refreshToken);

        if (!$userData) {
            return null;
        }

        // Update session with new tokens using TokenStorageService
        // This ensures both database and cache are updated atomically
        $success = $this->tokenStorage->updateSessionTokens($refreshToken, $tokens);

        if (!$success) {
            return null;
        }

        // Build OIDC-compliant user object (same as login response)
        $oidcUser = [
            'id' => $userData['uuid'],
            'email' => $userData['email'] ?? null,
            'email_verified' => !empty($userData['email_verified_at']),
            'username' => $userData['username'] ?? null,
            'locale' => $userData['locale'] ?? 'en-US',
            'updated_at' => isset($userData['updated_at']) ? strtotime($userData['updated_at']) : time()
        ];

        // Add name fields if profile exists
        if (isset($userData['profile'])) {
            $firstName = $userData['profile']['first_name'] ?? '';
            $lastName = $userData['profile']['last_name'] ?? '';

            if ($firstName || $lastName) {
                $oidcUser['name'] = trim($firstName . ' ' . $lastName);
                $oidcUser['given_name'] = $firstName ?: null;
                $oidcUser['family_name'] = $lastName ?: null;
            }

            if (!empty($userData['profile']['photo_url'])) {
                $oidcUser['picture'] = $userData['profile']['photo_url'];
            }
        }

        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_in' => $tokens['expires_in'],
            'token_type' => 'Bearer',
            'user' => $oidcUser
        ];
    }

    /**
     * Get user data from refresh token
     *
     * Retrieves user information associated with a refresh token.
     *
     * @param string $refreshToken Refresh token
     * @return array|null User data or null if token is invalid
     */
    private function getUserDataFromRefreshToken(string $refreshToken): ?array
    {
        // Use existing database connection
        $connection = new \Glueful\Database\Connection();
        $queryBuilder = new \Glueful\Database\QueryBuilder($connection->getPDO(), $connection->getDriver());

        $result = $queryBuilder->select('auth_sessions', ['user_uuid'])
            ->where(['refresh_token' => $refreshToken, 'status' => 'active'])
            ->limit(1)
            ->get();

        if (empty($result)) {
            return null;
        }

        $userUuid = $result[0]['user_uuid'];
        $user = $this->userRepository->findByUUID($userUuid);

        if (empty($user)) {
            return null;
        }

        $userData = $this->formatUserData($user);
        $userProfile = $this->userRepository->getProfile($userData['uuid']);
        // Note: Role functionality moved to RBAC extension
        $userRoles = []; // Use RBAC extension APIs for role management

        $userData['roles'] = $userRoles;
        $userData['profile'] = $userProfile;

        return $userData;
    }

    /**
     * Check if user is authenticated
     *
     * Uses the AuthenticationManager to verify if the request is authenticated.
     *
     * @param Request|mixed $request The request to check
     * @return bool True if authenticated, false otherwise
     */
    public static function checkAuth($request): bool
    {
        // Ensure we're working with a Request object
        if (!$request instanceof Request) {
            // Convert global variables to a Request object
            $request = Request::createFromGlobals();
        }

        // Use the authentication manager
        $authManager = AuthBootstrap::getManager();
        $userData = $authManager->authenticate($request);

        return $userData !== null;
    }

    /**
     * Check if user is authenticated and has admin privileges
     *
     * Uses the AuthenticationManager to verify admin authentication.
     *
     * @param Request|mixed $request The request to check
     * @return bool True if authenticated as admin, false otherwise
     */
    public static function checkAdminAuth($request): bool
    {
        // Ensure we're working with a Request object
        if (!$request instanceof Request) {
            // Convert global variables to a Request object
            $request = Request::createFromGlobals();
        }

        // Use the authentication manager
        $authManager = AuthBootstrap::getManager();
        $userData = $authManager->authenticate($request);

        if (!$userData) {
            return false;
        }

        return $authManager->isAdmin($userData);
    }



    /**
     * Authenticate with multiple providers
     *
     * Tries multiple authentication methods in sequence.
     * This is useful for APIs that support multiple authentication methods
     * like JWT tokens, API keys, OAuth, etc.
     *
     * @param Request $request The request to authenticate
     * @param array $providers Names of providers to try (e.g. 'jwt', 'api_key')
     * @return array|null User data if authenticated, null otherwise
     */
    public static function authenticateWithProviders(Request $request, array $providers = ['jwt', 'api_key']): ?array
    {
        $authManager = AuthBootstrap::getManager();
        return $authManager->authenticateWithProviders($providers, $request);
    }
}
