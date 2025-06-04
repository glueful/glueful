<?php

declare(strict_types=1);

namespace Glueful\DI\Providers;

use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\Database\Connection;
use Glueful\Repository\RepositoryFactory;
use Glueful\Repository\ResourceRepository;
use Glueful\Repository\UserRepository;
use Glueful\Repository\RoleRepository;
use Glueful\Repository\PermissionRepository;
use Glueful\Repository\NotificationRepository;
use Glueful\Repository\BlobRepository;

/**
 * Repository Service Provider
 *
 * Registers repository-related services in the DI container.
 * Provides centralized configuration for all repository dependencies.
 */
class RepositoryServiceProvider implements ServiceProviderInterface
{
    /**
     * {@inheritdoc}
     */
    public function register(ContainerInterface $container): void
    {
        // Register the repository factory as a singleton
        $container->singleton(RepositoryFactory::class, function (ContainerInterface $container) {
            $connection = $container->get(Connection::class);
            return new RepositoryFactory($connection);
        });

        // Register updated UserRepository
        $container->singleton(UserRepository::class, function (ContainerInterface $container) {
            $connection = $container->get(Connection::class);
            return new UserRepository($connection);
        });

        // Register generic resource repository
        $container->bind(ResourceRepository::class, function (ContainerInterface $container) {
            $connection = $container->get(Connection::class);
            // Note: This requires a table name to be provided when resolving
            return function (string $tableName) use ($connection) {
                return new ResourceRepository($tableName, $connection);
            };
        });

        // Register repository factory methods
        $container->bind('repository', function (ContainerInterface $container) {
            return $container->get(RepositoryFactory::class);
        });

        // Convenience method to get repositories by resource name
        $container->bind('repository.get', function (ContainerInterface $container) {
            $factory = $container->get(RepositoryFactory::class);
            return function (string $resource) use ($factory) {
                return $factory->getRepository($resource);
            };
        });

        // Keep existing legacy repositories for backward compatibility (already registered above)

        $container->bind(RoleRepository::class, function (ContainerInterface $container) {
            $connection = $container->get(Connection::class);
            return new RoleRepository($connection);
        });

        $container->bind(PermissionRepository::class, function (ContainerInterface $container) {
            $connection = $container->get(Connection::class);
            return new PermissionRepository($connection);
        });

        $container->bind(NotificationRepository::class, function (ContainerInterface $container) {
            $connection = $container->get(Connection::class);
            return new NotificationRepository($connection);
        });

        $container->bind(BlobRepository::class, function (ContainerInterface $container) {
            $connection = $container->get(Connection::class);
            return new BlobRepository($connection);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function boot(ContainerInterface $container): void
    {
        // No additional boot logic needed for repositories
    }
}
