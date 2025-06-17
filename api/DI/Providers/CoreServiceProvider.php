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
use Glueful\Permissions\PermissionManager;
use Glueful\Performance\MemoryManager;
use Symfony\Component\HttpFoundation\Request;

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
            // Create with default JWT provider (no explicit provider needed)
            return new AuthenticationManager();
        });

        // Request service - singleton to maintain state across the request lifecycle
        $container->singleton(Request::class, function ($container) {
            return Request::createFromGlobals();
        });

        // Permission services
        $container->singleton(PermissionManager::class, function ($container) {
            return PermissionManager::getInstance();
        });

        // Also register with string key for backward compatibility
        $container->singleton('permission.manager', function ($container) {
            return PermissionManager::getInstance();
        });

        // Performance services
        $container->singleton(MemoryManager::class, function () {
            // MemoryManager constructor takes optional LoggerInterface
            // Let it use NullLogger fallback since we don't have LoggerInterface registered yet
            return new MemoryManager();
        });
    }

    public function boot(ContainerInterface $container): void
    {
        // Initialize services that need booting
        // This runs after all providers have registered their services
    }
}
