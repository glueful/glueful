<?php

namespace Glueful\DI\Providers;

use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\Services\Archive\ArchiveService;
use Glueful\Services\Archive\ArchiveServiceInterface;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Security\RandomStringGenerator;

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
     * Boot services after all providers have been registered
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function boot(ContainerInterface $container): void
    {
        // Nothing to boot for archive service
    }

    /**
     * Register archive services in the container
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function register(ContainerInterface $container): void
    {
        // Register Archive Service Interface
        $container->bind(ArchiveServiceInterface::class, function () use ($container) {
            $config = $this->getArchiveConfig();

            return new ArchiveService(
                $container->get(QueryBuilder::class),
                $container->get(SchemaManager::class),
                $container->get(RandomStringGenerator::class),
                $config
            );
        });

        // Register as singleton to maintain state
        $container->singleton(ArchiveService::class, function () use ($container) {
            return $container->get(ArchiveServiceInterface::class);
        });
    }

    /**
     * Get archive configuration
     *
     * @return array
     */
    private function getArchiveConfig(): array
    {

        return [
            'storage_path' => config('archive.storage.path'),
            'encryption_key' => config('archive.encryption.key'),
            'compression' => config('archive.compression.algorithm'),
            'chunk_size' => config('archive.processing.chunk_size'),
            'verify_checksums' => config('archive.processing.verify_checksums'),
            'auto_cleanup_failed' => config('archive.processing.auto_cleanup_failed'),
            'max_archive_size' => config('archive.storage.max_archive_size'),
            'retention_policies' => config('archive.retention_policies')
        ];
    }
}
