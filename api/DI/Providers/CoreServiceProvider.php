<?php

declare(strict_types=1);

namespace Glueful\DI\Providers;

use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Cache\CacheFactory;
use Glueful\Cache\Drivers\CacheDriverInterface;
use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\TokenManager;
use Glueful\Helpers\ConfigManager;
use Glueful\Security\RandomStringGenerator;

/**
 * Core Service Provider
 *
 * Registers core framework services with the DI container
 */
class CoreServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        // Database services
        $container->singleton(Connection::class, function ($container) {
            return new Connection();
        });

        $container->bind(QueryBuilder::class, function ($container) {
            $connection = $container->get(Connection::class);
            return new QueryBuilder($connection->getPDO(), $connection->getDriver());
        });

        $container->singleton(SchemaManager::class, function ($container) {
            $connection = $container->get(Connection::class);
            return $connection->getSchemaManager();
        });

        // Security services
        $container->singleton(RandomStringGenerator::class, function ($container) {
            return new RandomStringGenerator();
        });

        // Cache services
        $container->singleton(CacheDriverInterface::class, function ($container) {
            return CacheFactory::create();
        });

        // Configuration service
        $container->singleton(ConfigManager::class, function ($container) {
            return new ConfigManager();
        });

        // Authentication services
        $container->singleton(TokenManager::class, function ($container) {
            return new TokenManager();
        });

        $container->singleton(AuthenticationManager::class, function ($container) {
            return new AuthenticationManager(
                $container->get(TokenManager::class)
            );
        });
    }

    public function boot(ContainerInterface $container): void
    {
        // Initialize services that need booting
        // This runs after all providers have registered their services
    }
}
