<?php
declare(strict_types=1);

namespace Glueful\Auth;

use Glueful\Repository\UserRepository;
use Glueful\DTOs\{PasswordDTO};
use Glueful\Validation\Validator;
use Glueful\Helpers\Utils;
use ParagonIE\Sodium\Core\Util;

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
 */
class AuthenticationService
{
    private UserRepository $userRepository;
    private Validator $validator;
    private PasswordHasher $passwordHasher;
    
    /**
     * Constructor
     * 
     * Initializes service dependencies.
     */
    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->validator = new Validator();
        $this->passwordHasher = new PasswordHasher();
    }
    
    /**
     * Authenticate user
     * 
     * Validates credentials and creates user session.
     * 
     * @param array $credentials User credentials
     * @return array|null Authentication result or null if failed
     */
    public function authenticate(array $credentials): ?array
    {   
        // Validate required fields
        if (!$this->validateCredentials($credentials)) {
            return null;
        }
        
        // Process credentials based on type (username or email)
        $user = null;
        
        if (isset($credentials['username'])) {
            if (filter_var($credentials['username'], FILTER_VALIDATE_EMAIL)) {
                $user = $this->userRepository->findByEmail($credentials['username']);
            } else {
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
        
        // Format user data
        $userData = $this->formatUserData($user);
        $userProfile = $this->userRepository->getProfile($userData['uuid']);
        $userRoles = $this->userRepository->getRoles($userData['uuid']);

        // return $userRole;
 
        // $userData['roles'] = $userRole;
        foreach ($userRoles as $userRole) {
            $userData['roles'] = [$userRole['role_name']];
        }
        
        $userData['profile'] = $userProfile;
        $userData['last_login'] = date('Y-m-d H:i:s');
        $userSession = TokenManager::createUserSession($userData);
       
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
        TokenManager::revokeSession($token);
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
    public function validateAccessToken(string $token): ?array
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
     * Gets authentication token from current request.
     * 
     * @return string|null Authentication token
     */
    public function extractTokenFromRequest(): ?string
    {
        return TokenManager::extractTokenFromRequest();
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
     * 
     * @param string $token Current authentication token
     * @return array Response with new token and updated permissions
     */
    public function refreshPermissions(string $token): mixed 
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

        // Get fresh user roles
        $userRoles = $this->userRepository->getRoles($userUuid);
        
        // Update session with new roles
        $session['user']['roles'] = $userRoles;
        
        // Generate new token with updated session data
        $tokenLifetime = (int)config('session.access_token_lifetime');
        $newToken = JWTService::generate($session, $tokenLifetime);
        
        // Update session storage
        SessionCacheManager::updateSession($token, $session, $newToken);

        return [
            'token' => $newToken,
            'permissions' => $userRoles
        ];
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

        // Return true if user was found and is properly formatted
        return !empty($user) && is_array($user) && !isset($user['username']);
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
        
        // Update session with new tokens
        $userData['refresh_token'] = $tokens['refresh_token'];
        SessionCacheManager::storeSession($userData, $tokens['access_token']);
        
        return [
            'tokens' => [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'expires_in' => $tokens['expires_in'],
                'token_type' => 'Bearer'
            ],
            'user' => $userData
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
        
        $userData = $this->formatUserData($user[0]);
        $userProfile = $this->userRepository->getProfile($userData['uuid']);
        $userRoles = $this->userRepository->getRoles($userData['uuid']);
        
        $userData['roles'] = $userRoles;
        $userData['profile'][] = $userProfile;
        
        return $userData;
    }

    public static function checkAuth($request): bool
    {
    
        $token = self::extractTokenFromRequest($request);
        if (!$token) {
            return false;
        }
        return self::validateToken($token);
    }

    public static function checkAdminAuth($request): bool
    {
        $token = self::extractTokenFromRequest($request);
        if (!$token) {
            return false;
        }

        $session = self::validateAccessToken($token);
        if (!$session) {
            return false;
        }
        
        $user = Utils::getUser($token);
        if (!$user) {
            return false;
        }

        if (!in_array('superuser', $user['roles'])) {
            return false;
        }

        return true;
    }

    private static function validateToken(string $token): bool
    {   
        $result = self::validateAccessToken($token);

        if (!$result) {
            return false;
        }
        
        return true;
    }
}