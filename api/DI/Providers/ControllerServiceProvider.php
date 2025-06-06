<?php

declare(strict_types=1);

namespace Glueful\DI\Providers;

use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Interfaces\ServiceProviderInterface;
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
use Glueful\Repository\RepositoryFactory;

/**
 * Controller Service Provider
 *
 * Registers controller services with the DI container
 */
class ControllerServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Auth Controller - currently creates its own dependencies
        $container->bind(AuthController::class);

        // Config Controller - currently uses static ConfigManager
        $container->bind(ConfigController::class);

        // Database Controller
        $container->bind(DatabaseController::class);

        // Resource Controller
        $container->bind(ResourceController::class, function (ContainerInterface $container) {
            $repositoryFactory = $container->get(RepositoryFactory::class);
            return new ResourceController($repositoryFactory);
        });

        // Metrics Controller
        $container->bind(MetricsController::class);

        // Migrations Controller
        $container->bind(MigrationsController::class);

        // Jobs Controller
        $container->bind(JobsController::class);

        // Files Controller
        $container->bind(FilesController::class);

        // Health Controller
        $container->bind(HealthController::class);

        // Notifications Controller
        $container->bind(NotificationsController::class);

        // Extensions Controller
        $container->bind(ExtensionsController::class);
    }

    public function boot(ContainerInterface $container): void
    {
        // Controller initialization if needed
    }
}
