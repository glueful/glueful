<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\Services\FileManager;
use Glueful\Services\FileFinder;
use Psr\Log\LoggerInterface;

/**
 * File Service Provider
 *
 * Registers file-related services (FileManager and FileFinder) with the DI container.
 * Configures services with appropriate logging and configuration options.
 */
class FileServiceProvider implements ServiceProviderInterface
{
    /**
     * Register file services with the container
     *
     * @param ContainerInterface $container DI container
     */
    public function register(ContainerInterface $container): void
    {
        // Register FileManager service
        $container->singleton(FileManager::class, function ($container) {
            $logger = null;

            // Try to get logger if available
            try {
                $logger = $container->get(LoggerInterface::class);
            } catch (\Exception) {
                // Logger not available, continue without it
            }

            // Get file manager configuration
            $config = config('filesystem.file_manager', []);

            return new FileManager($logger, $config);
        });

        // Register FileFinder service
        $container->singleton(FileFinder::class, function ($container) {
            $logger = null;

            // Try to get logger if available
            try {
                $logger = $container->get(LoggerInterface::class);
            } catch (\Exception) {
                // Logger not available, continue without it
            }

            // Get file finder configuration
            $config = config('filesystem.file_finder', []);

            return new FileFinder($logger, $config);
        });

        // Register aliases for easier access (following QueueServiceProvider pattern)
        $container->singleton('file.manager', function ($container) {
            return $container->get(FileManager::class);
        });

        $container->singleton('file.finder', function ($container) {
            return $container->get(FileFinder::class);
        });
    }

    /**
     * Boot method called after all services are registered
     *
     * @param ContainerInterface $container DI container
     */
    public function boot(ContainerInterface $container): void
    {
        // No additional boot logic needed for file services
    }
}
