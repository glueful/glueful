<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\Container;
use Glueful\Services\FileManager;
use Glueful\Services\FileFinder;

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
     * @param ContainerBuilder $container DI container
     */
    public function register(ContainerBuilder $container): void
    {
        // Register FileManager service
        $container->register(FileManager::class)
            ->setFactory([$this, 'createFileManager'])
            ->setArguments([new Reference('logger'), '%filesystem.file_manager%'])
            ->setPublic(true);

        // Register FileFinder service
        $container->register(FileFinder::class)
            ->setFactory([$this, 'createFileFinder'])
            ->setArguments([new Reference('logger'), '%filesystem.file_finder%'])
            ->setPublic(true);

        // Register aliases for easier access
        $container->setAlias('file.manager', FileManager::class);
        $container->setAlias('file.finder', FileFinder::class);
    }

    /**
     * Boot method called after all services are registered
     *
     * @param Container $container DI container
     */
    public function boot(Container $container): void
    {
        // No additional boot logic needed for file services
    }

    /**
     * Get compiler passes for file services
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
        return 'file';
    }

    /**
     * Factory method for creating FileManager
     */
    public static function createFileManager($logger, $config): FileManager
    {
        return new FileManager($logger, $config);
    }

    /**
     * Factory method for creating FileFinder
     */
    public static function createFileFinder($logger, $config): FileFinder
    {
        return new FileFinder($logger, $config);
    }
}
