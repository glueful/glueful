<?php

declare(strict_types=1);

namespace Glueful\Extensions\OAuthServer\Auth\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
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
     * Get a user entity by user credentials.
     *
     * @param string                $username
     * @param string                $password
     * @param string                $grantType    The grant type used
     * @param ClientEntityInterface $clientEntity
     *
     * @return UserEntityInterface|null
     */
    public function getUserEntityByUserCredentials(
        $username,
        $password,
        $grantType,
        ClientEntityInterface $clientEntity
    ): ?UserEntityInterface {
        // Find user by username or email
        $user = $this->queryBuilder->select('users', ['id', 'email', 'password', 'name'])
            ->where([
                'OR' => [
                    'username' => $username,
                    'email' => $username
                ],
                'active' => true
            ])
            ->first();

        if ($user === null) {
            return null; // User not found
        }

        // Verify password
        if (!password_verify($password, $user['password'])) {
            return null; // Invalid password
        }

        // Create and return user entity
        $userEntity = new UserEntity();
        $userEntity->setIdentifier($user['id']);
        $userEntity->setEmail($user['email']);
        $userEntity->setUsername($user['name']);

        return $userEntity;
    }

    /**
     * Find a user by their identifier
     *
     * @param string|int $identifier User ID
     * @return UserEntityInterface|null
     */
    public function getUserEntityByIdentifier($identifier): ?UserEntityInterface
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
