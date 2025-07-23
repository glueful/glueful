<?php

declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin\Providers;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Auth\Interfaces\AuthenticationProviderInterface;
use Glueful\Repository\UserRepository;
use Glueful\Auth\TokenManager;
use Glueful\Auth\JWTService;
use Glueful\Helpers\Utils;

/**
 * Abstract Social Authentication Provider
 *
 * Base class for all social authentication providers.
 * Implements common functionality and defines the contract
 * for provider-specific implementations.
 *
 * @package Glueful\Extensions\SocialLogin\Providers
 */
abstract class AbstractSocialProvider implements AuthenticationProviderInterface
{
    /** @var string Name of the provider (e.g., 'google', 'facebook') */
    protected string $providerName;

    /** @var string|null Last authentication error */
    protected ?string $lastError = null;

    /** @var UserRepository User repository for database operations */
    protected UserRepository $userRepository;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->userRepository = new UserRepository();
    }

    /**
     * Authenticate a request
     *
     * Processes OAuth authentication flow and returns user data.
     *
     * @param Request $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    public function authenticate(Request $request): ?array
    {
        try {
            // Check if this is an OAuth callback
            if ($this->isOAuthCallback($request)) {
                return $this->handleCallback($request);
            }

            // Check if this is an OAuth initialization request
            if ($this->isOAuthInitRequest($request)) {
                $this->initiateOAuthFlow($request);
                return null; // Will redirect, not return data
            }
        } catch (\Exception $e) {
            $this->lastError = "Authentication error: " . $e->getMessage();
            error_log("[{$this->providerName}] " . $this->lastError);
            return null;
        }

        // Not an OAuth request that we can handle
        return null;
    }

    /**
     * Check if a user has admin privileges
     *
     * @param array $userData User data from successful authentication
     * @return bool True if user has admin privileges, false otherwise
     */
    public function isAdmin(array $userData): bool
    {
        // Check if user has superuser role
        if (!isset($userData['uuid'])) {
            return false;
        }

        // We need to check if the user has the admin role
        $roles = $userData['roles'] ?? [];
        return in_array('superuser', $roles);
    }

    /**
     * Get the current authentication error, if any
     *
     * @return string|null The authentication error message or null if no error
     */
    public function getError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Validate a token
     *
     * @param string $token The token to validate
     * @return bool True if token is valid, false otherwise
     */
    public function validateToken(string $token): bool
    {
        // Use the correct TokenManager method for validation
        return TokenManager::validateAccessToken($token);
    }

    /**
     * Check if this provider can handle a given token
     *
     * @param string $token The token to check
     * @return bool True if this provider can validate this token
     */
    public function canHandleToken(string $token): bool
    {
        // Since TokenManager doesn't have decodeToken method, we'll use JWTService
        $decoded = JWTService::decode($token);
        if (!$decoded) {
            return false;
        }

        // Check if the token was issued by this provider
        return isset($decoded['provider']) && $decoded['provider'] === $this->providerName;
    }

    /**
     * Generate authentication tokens
     *
     * @param array $userData User data to include in tokens
     * @param int|null $accessTokenLifetime Custom access token lifetime
     * @param int|null $refreshTokenLifetime Custom refresh token lifetime
     * @return array Generated tokens
     */
    public function generateTokens(
        array $userData,
        ?int $accessTokenLifetime = null,
        ?int $refreshTokenLifetime = null
    ): array {
        // Add provider information to the token claims
        $userData['provider'] = $this->providerName;

        // Use the correct TokenManager method to generate tokens
        return TokenManager::generateTokenPair($userData, $accessTokenLifetime, $refreshTokenLifetime);
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
        // Add provider information to the session data
        $sessionData['provider'] = $this->providerName;

        // Use the core TokenManager to refresh tokens
        try {
            // TokenManager::refreshTokens expects the provider name as the second parameter, not session data
            return TokenManager::refreshTokens($refreshToken, $this->providerName);
        } catch (\Exception $e) {
            $this->lastError = "Token refresh error: " . $e->getMessage();
            return null;
        }
    }

    /**
     * Check if request is a social OAuth callback
     *
     * @param Request $request The HTTP request
     * @return bool True if this is a callback request
     */
    abstract protected function isOAuthCallback(Request $request): bool;

    /**
     * Check if request is to initialize OAuth flow
     *
     * @param Request $request The HTTP request
     * @return bool True if this is an initialization request
     */
    abstract protected function isOAuthInitRequest(Request $request): bool;

    /**
     * Handle OAuth callback
     *
     * Process callback from social provider, validate token/code,
     * and retrieve user information.
     *
     * @param Request $request The HTTP request
     * @return array|null User data if authenticated, null otherwise
     */
    abstract protected function handleCallback(Request $request): ?array;

    /**
     * Initiate OAuth flow
     *
     * Generate authorization URL and redirect user to social provider.
     *
     * @param Request $request The HTTP request
     * @return void
     */
    abstract protected function initiateOAuthFlow(Request $request): void;

    /**
     * Find or create user from social data
     *
     * @param array $socialData Data retrieved from social provider
     * @return array|null User data if created/found, null otherwise
     */
    protected function findOrCreateUser(array $socialData): ?array
    {
        // Implementation depends on user repository structure
        // This should:
        // 1. Look for existing user with this social ID
        // 2. If not found, look for user with same email
        // 3. If not found, create new user if auto-registration is enabled
        // 4. Return user data

        // First check if we have a user with this social ID
        $existingUser = $this->findUserBySocialId(
            $this->providerName,
            $socialData['id']
        );

        if ($existingUser) {
            return $this->formatUserData($existingUser);
        }

        // Check if we have a user with the same email
        if (!empty($socialData['email'])) {
            $emailUser = $this->userRepository->findByEmail($socialData['email']);

            if ($emailUser) {
                // Link the accounts
                $this->linkSocialAccount(
                    $emailUser['uuid'],
                    $this->providerName,
                    $socialData['id'],
                    $socialData
                );

                return $this->formatUserData($emailUser);
            }
        }

        // No existing user found, try to create new user if auto-registration is enabled
        $config = \Glueful\Extensions\SocialLogin::getConfig();
        if (!($config['auto_register'] ?? true)) {
            $this->lastError = "Auto-registration is disabled and no matching user found";
            return null;
        }

        // Create new user
        return $this->createUserFromSocial($socialData);
    }

    /**
     * Find a user by social ID
     *
     * @param string $provider Provider name
     * @param string $socialId Social identifier
     * @return array|null User data if found, null otherwise
     */
    protected function findUserBySocialId(string $provider, string $socialId): ?array
    {
        // Implementation depends on database schema
        // Assuming social_accounts table with provider, social_id, user_uuid columns

        // This is a simplified implementation
        // In a real system, you would query the social_accounts table

        $connection = new \Glueful\Database\Connection();
        $db = new \Glueful\Database\QueryBuilder(
            $connection->getPDO(),
            $connection->getDriver()
        );

        $result = $db->select('social_accounts', ['user_uuid'])
            ->where([
                'provider' => $provider,
                'social_id' => $socialId
            ])
            ->limit(1)
            ->get();

        if (empty($result)) {
            return null;
        }

        // Get the full user data - using findByUUID instead of find
        return $this->userRepository->findByUUID($result[0]['user_uuid']);
    }

    /**
     * Link social account to user
     *
     * @param string $userUuid User UUID
     * @param string $provider Provider name
     * @param string $socialId Social identifier
     * @param array $userData Additional user data from provider
     * @return bool Success indicator
     */
    protected function linkSocialAccount(
        string $userUuid,
        string $provider,
        string $socialId,
        array $userData
    ): bool {
        // Implementation depends on database schema
        // This is a simplified implementation

        $connection = new \Glueful\Database\Connection();
        $db = new \Glueful\Database\QueryBuilder(
            $connection->getPDO(),
            $connection->getDriver()
        );

        // Check if the link already exists
        $existing = $db->select('social_accounts')
            ->where([
                'user_uuid' => $userUuid,
                'provider' => $provider,
                'social_id' => $socialId
            ])
            ->limit(1)
            ->get();

        if (!empty($existing)) {
            // Already linked, update the data using upsert instead of update
            return $db->upsert(
                'social_accounts',
                [
                    [
                        'uuid' => $existing[0]['uuid'] ?? Utils::generateNanoID(),
                        'user_uuid' => $userUuid,
                        'provider' => $provider,
                        'profile_data' => json_encode($userData),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ],
                ['profile_data', 'updated_at']
            ) > 0;
        }

        // Create new link - convert integer result to boolean by comparing with zero
        $result = $db->insert('social_accounts', [
            'uuid' => Utils::generateNanoID(),
            'user_uuid' => $userUuid,
            'provider' => $provider,
            'social_id' => $socialId,
            'profile_data' => json_encode($userData),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return $result > 0;
    }

    /**
     * Create a new user from social data
     *
     * @param array $socialData Data from social provider
     * @return array|null User data if created, null otherwise
     */
    protected function createUserFromSocial(array $socialData): ?array
    {
        // Generate a username based on the social data
        $username = $this->generateUsername($socialData);

        // Create user data array
        $userData = [
            'username' => $username,
            'email' => $socialData['email'] ?? null,
            'password' => Utils::generateSecurePassword(16), // Random password
            'status' => 'active'
        ];

        // Create the user
        $userUuid = $this->userRepository->create($userData);

        if (!$userUuid) {
            $this->lastError = "Failed to create user";
            return null;
        }

        // Link the social account
        $this->linkSocialAccount(
            $userUuid,
            $this->providerName,
            $socialData['id'],
            $socialData
        );

        // Create profile data
        $profileData = [
            'first_name' => $socialData['first_name'] ?? null,
            'last_name' => $socialData['last_name'] ?? null,
            'display_name' => $socialData['name'] ?? $username,
            'avatar_url' => $socialData['picture'] ?? null
        ];

        // Create or update profile
        $this->userRepository->updateProfile($userUuid, $profileData);

        // Get the full user data - using findByUUID instead of find
        return $this->userRepository->findByUUID($userUuid);
    }

    /**
     * Generate username from social data
     *
     * @param array $socialData Social provider data
     * @return string Generated username
     */
    protected function generateUsername(array $socialData): string
    {
        // Try to use name parts if available
        if (isset($socialData['first_name']) && isset($socialData['last_name'])) {
            $base = strtolower($socialData['first_name'] . $socialData['last_name']);
        } elseif (isset($socialData['name'])) {
            $base = strtolower(str_replace(' ', '', $socialData['name']));
        } elseif (isset($socialData['email'])) {
            $base = strtolower(explode('@', $socialData['email'])[0]);
        } else {
            $base = 'user';
        }

        // Sanitize
        $base = preg_replace('/[^a-z0-9]/', '', $base);

        // Ensure it's not too long
        $base = substr($base, 0, 15);

        // Check if username exists
        $existing = $this->userRepository->findByUsername($base);

        if (!$existing) {
            return $base;
        }

        // Username exists, add a random suffix
        return $base . rand(100, 999);
    }

    /**
     * Format user data for authentication response
     *
     * @param array $user User data from repository
     * @return array Formatted user data
     */
    protected function formatUserData(array $user): array
    {

        // Get user profile
        $profile = $this->userRepository->getProfile($user['uuid']);

        // Format user data
        return [
            'uuid' => $user['uuid'],
            'username' => $user['username'],
            'email' => $user['email'],
            'status' => $user['status'],
            'profile' => $profile,
            'provider' => $this->providerName,
            'last_login' => date('Y-m-d H:i:s')
        ];
    }
}
