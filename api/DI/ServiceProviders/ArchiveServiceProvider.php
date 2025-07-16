<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\Container;
use Glueful\Services\Archive\ArchiveService;
use Glueful\Services\Archive\ArchiveServiceInterface;

/**
 * Archive Service Provider
 *
 * Registers archive services with the dependency injection container.
 * Configures the archive service with appropriate dependencies and settings.
 *
 * @package Glueful\DI\Providers
 */
class ArchiveServiceProvider implements ServiceProviderInterface
{
    /**
     * Register archive services in Symfony ContainerBuilder
     *
     * @param ContainerBuilder $container
     * @return void
     */
    public function register(ContainerBuilder $container): void
    {
        // Register Archive Service with factory method
        $container->register(ArchiveServiceInterface::class)
            ->setFactory([$this, 'createArchiveService'])
            ->setArguments([
                new Reference(\Glueful\Database\QueryBuilder::class),
                new Reference(\Glueful\Database\Schema\SchemaManager::class),
                new Reference(\Glueful\Security\RandomStringGenerator::class),
                '%archive.config%'
            ])
            ->setPublic(true);

        // Register alias for ArchiveService
        $container->setAlias(ArchiveService::class, ArchiveServiceInterface::class);
    }

    /**
     * Boot services after container is built
     *
     * @param Container $container
     * @return void
     */
    public function boot(Container $container): void
    {
        // Nothing to boot for archive service
    }

    /**
     * Get compiler passes for archive services
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
        return 'archive';
    }

    /**
     * Factory method for creating ArchiveService
     */
    public static function createArchiveService(
        $queryBuilder,
        $schemaManager,
        $randomStringGenerator,
        $config
    ): ArchiveService {
        return new ArchiveService(
            $queryBuilder,
            $schemaManager,
            $randomStringGenerator,
            $config
        );
    }
}
