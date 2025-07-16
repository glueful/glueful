<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\ServiceTags;
use Glueful\DI\Container;
use Glueful\Controllers\AuthController;
use Glueful\Controllers\ConfigController;
use Glueful\Controllers\DatabaseController;
use Glueful\Controllers\ResourceController;
use Glueful\Controllers\MetricsController;
use Glueful\Controllers\MigrationsController;
use Glueful\Controllers\JobsController;
use Glueful\Controllers\FilesController;
use Glueful\Controllers\HealthController;
use Glueful\Controllers\NotificationsController;
use Glueful\Controllers\ExtensionsController;

/**
 * Controller Service Provider
 *
 * Registers controller services with the DI container
 */
class ControllerServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        // Auth Controller - currently creates its own dependencies
        $container->register(AuthController::class)
            ->setPublic(true);

        // Config Controller - currently uses static ConfigManager
        $container->register(ConfigController::class)
            ->setPublic(true);

        // Database Controller
        $container->register(DatabaseController::class)
            ->setPublic(true);

        // Resource Controller
        $container->register(ResourceController::class)
            ->setArguments([new Reference(\Glueful\Repository\RepositoryFactory::class)])
            ->setPublic(true);

        // Metrics Controller
        $container->register(MetricsController::class)
            ->setPublic(true);

        // Migrations Controller
        $container->register(MigrationsController::class)
            ->setPublic(true);

        // Jobs Controller
        $container->register(JobsController::class)
            ->setPublic(true);

        // Files Controller - inject dependencies for BaseController compatibility
        $container->register(FilesController::class)
            ->setArguments([new Reference(\Glueful\Repository\RepositoryFactory::class)])
            ->setPublic(true);

        // Health Controller - inject dependencies for BaseController
        $container->register(HealthController::class)
            ->setArguments([new Reference(\Glueful\Repository\RepositoryFactory::class)])
            ->setPublic(true);

        // Notifications Controller
        $container->register(NotificationsController::class)
            ->setPublic(true);

        // Extensions Controller
        $container->register(ExtensionsController::class)
            ->setPublic(true);
    }

    public function boot(Container $container): void
    {
        // Controller initialization if needed
    }

    /**
     * Get compiler passes for controller services
     */
    public function getCompilerPasses(): array
    {
        return [];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'controllers';
    }
}
