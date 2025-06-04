<?php

declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\Database\Connection;
use Glueful\Repository\Interfaces\RepositoryInterface;

/**
 * Repository Factory
 *
 * Creates repository instances using the unified repository pattern.
 * Provides centralized repository instantiation with dependency injection.
 */
class RepositoryFactory
{
    private Connection $connection;
    private array $repositories = [];

    public function __construct(?Connection $connection = null)
    {
        $this->connection = $connection ?? new Connection();
    }

    /**
     * Get repository for a specific resource/table
     *
     * @param string $resource The resource/table name
     * @return RepositoryInterface
     */
    public function getRepository(string $resource): RepositoryInterface
    {
        // Return cached instance if available
        if (isset($this->repositories[$resource])) {
            return $this->repositories[$resource];
        }

        // Create repository based on resource name
        $repository = match ($resource) {
            'users' => new UserRepository($this->connection),
            default => new ResourceRepository($resource, $this->connection)
        };

        // Cache the repository instance
        $this->repositories[$resource] = $repository;

        return $repository;
    }

    /**
     * Get a specific typed repository
     *
     * @template T
     * @param class-string<T> $repositoryClass
     * @return T
     */
    public function get(string $repositoryClass)
    {
        // Return cached instance if available
        if (isset($this->repositories[$repositoryClass])) {
            return $this->repositories[$repositoryClass];
        }

        // Create repository instance
        $repository = new $repositoryClass($this->connection);

        // Cache the repository instance
        $this->repositories[$repositoryClass] = $repository;

        return $repository;
    }

    /**
     * Get the users repository
     *
     * @return UserRepository
     */
    public function users(): UserRepository
    {
        /** @var UserRepository */
        return $this->getRepository('users');
    }

    /**
     * Get the roles repository
     *
     * @return RoleRepository
     */
    public function roles(): RoleRepository
    {
        /** @var RoleRepository */
        return $this->get(RoleRepository::class);
    }

    /**
     * Get the permissions repository
     *
     * @return PermissionRepository
     */
    public function permissions(): PermissionRepository
    {
        /** @var PermissionRepository */
        return $this->get(PermissionRepository::class);
    }

    /**
     * Get the notifications repository
     *
     * @return NotificationRepository
     */
    public function notifications(): NotificationRepository
    {
        /** @var NotificationRepository */
        return $this->get(NotificationRepository::class);
    }

    /**
     * Clear repository cache
     */
    public function clearCache(): void
    {
        $this->repositories = [];
    }

    /**
     * Get the database connection
     */
    public function getConnection(): Connection
    {
        return $this->connection;
    }
}
