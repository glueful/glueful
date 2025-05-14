<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\OAuthServer\Auth\OAuth\Entities\UserEntity;
use Glueful\Repository\UserRepository as AppUserRepository;

/**
 * OAuth User Repository
 *
 * Handles user authentication for OAuth 2.0 flows.
 * Leverages the application's main UserRepository when appropriate.
 */
class UserRepository implements UserRepositoryInterface
{
    /**
     * @var QueryBuilder Database query builder instance
     */
    private QueryBuilder $queryBuilder;

    /**
     * @var AppUserRepository Main application user repository
     */
    private AppUserRepository $appUserRepository;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Initialize database connection and query builder
        $connection = new Connection();
        $this->queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        // Initialize the main application user repository
        $this->appUserRepository = new AppUserRepository();
    }

    /**
     * Get a user entity for the given credentials
     *
     * This method is used during the OAuth password grant flow.
     *
     * @param string $username Username or email
     * @param string $password Password
     * @param string $grantType The grant type used
     * @param ClientEntityInterface $clientEntity The client entity
     * @return UserEntity|null
     */
    public function getUserEntityByUserCredentials(
        $username,
        $password,
        $grantType,
        ClientEntityInterface $clientEntity
    ) {
        // First check if this is an email or username
        $isEmail = filter_var($username, FILTER_VALIDATE_EMAIL);

        // Use the appropriate method from the main user repository
        $user = null;

        try {
            if ($isEmail) {
                // Try to find user by email
                $user = $this->appUserRepository->findByEmail($username);
            } else {
                // Try to find user by username
                $user = $this->appUserRepository->findByUsername($username);
            }
        } catch (\Exception $e) {
            return null; // User not found or other error
        }

        if (!$user) {
            return null;
        }

        // Check if user is active
        if (isset($user['status']) && $user['status'] !== 'active') {
            return null;
        }

        // Verify the password directly since we don't have a verifyPassword method
        if (!isset($user['password'])) {
            return null;
        }

        // Use password_verify for hashed passwords
        if (!password_verify($password, $user['password'])) {
            return null; // Password doesn't match
        }

        // Create OAuth user entity
        $userEntity = new UserEntity();
        $userEntity->setIdentifier($user['id'] ?? $user['uuid'] ?? null);
        $userEntity->setUsername($user['username'] ?? '');
        $userEntity->setEmail($user['email'] ?? '');

        // Add additional properties that might be useful
        if (isset($user['role'])) {
            $userEntity->setRole($user['role']);
        }

        return $userEntity;
    }

    /**
     * Find a user by their identifier
     *
     * @param string|int $identifier User ID
     * @return UserEntity|null
     */
    public function getUserEntityByIdentifier($identifier)
    {
        // Try to find user by ID - leveraging the main UserRepository
        try {
            $user = $this->appUserRepository->findByUUID($identifier);
        } catch (\Exception $e) {
            return null; // User not found
        }

        if (!$user) {
            return null;
        }

        // Create OAuth user entity
        $userEntity = new UserEntity();
        $userEntity->setIdentifier($user['id']);
        $userEntity->setUsername($user['username'] ?? '');
        $userEntity->setEmail($user['email'] ?? '');

        // Add additional properties that might be useful
        if (isset($user['role'])) {
            $userEntity->setRole($user['role']);
        }

        return $userEntity;
    }
}
