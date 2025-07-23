<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\Container;
use Glueful\Repository\RepositoryFactory;
use Glueful\Repository\ResourceRepository;
use Glueful\Repository\UserRepository;
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
    public function register(ContainerBuilder $container): void
    {
        // Register the repository factory
        $container->register(RepositoryFactory::class)
            ->setArguments([new Reference('database')])
            ->setPublic(true);

        // Register updated UserRepository
        $container->register(UserRepository::class)
            ->setArguments([new Reference('database')])
            ->setPublic(true);

        // Register ResourceRepository
        $container->register(ResourceRepository::class)
            ->setArguments([new Reference('database')])
            ->setPublic(true);

        // Register NotificationRepository
        $container->register(NotificationRepository::class)
            ->setArguments([new Reference('database')])
            ->setPublic(true);

        // Register BlobRepository
        $container->register(BlobRepository::class)
            ->setArguments([new Reference('database')])
            ->setPublic(true);

        // Register repository convenience aliases
        $container->setAlias('repository', RepositoryFactory::class);
        $container->setAlias('repository.user', UserRepository::class);
        $container->setAlias('repository.resource', ResourceRepository::class);
        $container->setAlias('repository.notification', NotificationRepository::class);
        $container->setAlias('repository.blob', BlobRepository::class);
    }

    public function boot(Container $container): void
    {
        // No additional boot logic needed for repositories
    }

    /**
     * Get compiler passes for repository services
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
        return 'repositories';
    }
}
