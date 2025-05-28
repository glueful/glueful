<?php

declare(strict_types=1);

namespace Glueful\DI\Providers;

use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\Repository\UserRepository;
use Glueful\Repository\RoleRepository;
use Glueful\Repository\PermissionRepository;
use Glueful\Repository\NotificationRepository;
use Glueful\Database\Connection;

/**
 * Repository Service Provider
 *
 * Registers repository services with the DI container
 */
class RepositoryServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // User repository
        $container->bind(UserRepository::class, function ($container) {
            return new UserRepository();
        });

        // Role repository
        $container->bind(RoleRepository::class, function ($container) {
            return new RoleRepository();
        });

        // Permission repository
        $container->bind(PermissionRepository::class, function ($container) {
            return new PermissionRepository();
        });

        // Notification repository
        $container->bind(NotificationRepository::class, function ($container) {
            return new NotificationRepository();
        });
    }

    public function boot(ContainerInterface $container): void
    {
        // Repository initialization if needed
    }
}
