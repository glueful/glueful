<?php

declare(strict_types=1);

namespace Glueful\DI\Providers;

use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Cache\CacheFactory;
use Glueful\Cache\CacheStore;
use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\TokenManager;
use Glueful\Helpers\ConfigManager;
use Glueful\Security\RandomStringGenerator;
use Glueful\Permissions\PermissionManager;
use Glueful\Permissions\PermissionCache;
use Glueful\Performance\MemoryManager;
use Glueful\Auth\SessionAnalytics;
use Glueful\Auth\SessionCacheManager;
use Glueful\Auth\SessionTransaction;
use Glueful\Auth\AuthenticationService;
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
        $container->singleton(CacheStore::class, function ($container) {
            return CacheFactory::create();
        });

        // Configuration service
        $container->singleton(ConfigManager::class, function ($container) {
            return new ConfigManager();
        });

        // Authentication services
        $container->singleton(TokenManager::class, function ($container) {
            // Initialize TokenManager with cache dependency
            TokenManager::initialize($container->get(CacheStore::class));
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

        // Permission cache service
        $container->singleton(PermissionCache::class, function ($container) {
            return new PermissionCache(
                $container->get(CacheStore::class)
            );
        });

        // Session analytics service
        $container->singleton(SessionAnalytics::class, function ($container) {
            return new SessionAnalytics(
                $container->get(CacheStore::class),
                $container->get(SessionCacheManager::class)
            );
        });

        // Session cache manager service
        $container->singleton(SessionCacheManager::class, function ($container) {
            return new SessionCacheManager(
                $container->get(CacheStore::class)
            );
        });

        // Authentication service
        $container->singleton(AuthenticationService::class, function ($container) {
            return new AuthenticationService(
                null, // TokenStorageInterface will use default
                $container->get(SessionCacheManager::class)
            );
        });

        // Session transaction service
        $container->bind(SessionTransaction::class, function ($container) {
            return new SessionTransaction(
                $container->get(SessionCacheManager::class),
                $container->get(CacheStore::class)
            );
        });

        // Performance services
        $container->singleton(MemoryManager::class, function () {
            // MemoryManager constructor takes optional LoggerInterface
            // Let it use NullLogger fallback since we don't have LoggerInterface registered yet
            return new MemoryManager();
        });

        // Rate limiter services
        $container->bind(\Glueful\Security\RateLimiter::class, function ($container) {
            // Rate limiter instances are created with specific parameters
            // This is just a default registration for when cache is needed
            return new \Glueful\Security\RateLimiter(
                'default',
                100,
                3600,
                $container->get(CacheStore::class)
            );
        });

        $container->bind(\Glueful\Security\AdaptiveRateLimiter::class, function ($container) {
            // Adaptive rate limiter instances are created with specific parameters
            // This is just a default registration for when cache is needed
            return new \Glueful\Security\AdaptiveRateLimiter(
                'default_adaptive',
                100,
                3600,
                [],
                false,
                $container->get(CacheStore::class)
            );
        });

        $container->bind(\Glueful\Security\AuthFailureTracker::class, function ($container) {
            // Auth failure tracker instances are created with specific parameters
            // This is just a default registration for when cache is needed
            return new \Glueful\Security\AuthFailureTracker(
                'default',
                5,
                900,
                $container->get(CacheStore::class)
            );
        });

        // API metrics service - now using Constructor Injection with Optional Dependencies
        $container->singleton(\Glueful\Services\ApiMetricsService::class, function ($container) {
            return new \Glueful\Services\ApiMetricsService(
                cache: $container->get(CacheStore::class),
                connection: $container->get(Connection::class),
                schemaManager: $container->get(SchemaManager::class)
            );
        });

        // Health service
        $container->singleton(\Glueful\Services\HealthService::class, function ($container) {
            return new \Glueful\Services\HealthService(
                $container->get(CacheStore::class)
            );
        });

        // Email verification service
        $container->singleton(\Glueful\Security\EmailVerification::class, function ($container) {
            return new \Glueful\Security\EmailVerification(
                null, // RequestContext will use default
                $container->get(CacheStore::class)
            );
        });

        // Security manager service
        $container->singleton(\Glueful\Security\SecurityManager::class, function ($container) {
            return new \Glueful\Security\SecurityManager(
                $container->get(CacheStore::class)
            );
        });

        // Cache warmup service
        $container->singleton(\Glueful\Cache\CacheWarmupService::class, function ($container) {
            return new \Glueful\Cache\CacheWarmupService(
                $container->get(CacheStore::class)
            );
        });

        // Distributed cache service
        $container->singleton(\Glueful\Cache\DistributedCacheService::class, function ($container) {
            return new \Glueful\Cache\DistributedCacheService(
                $container->get(CacheStore::class),
                config('cache.distributed', [])
            );
        });

        // Rate limiter distributor service
        $container->singleton(\Glueful\Security\RateLimiterDistributor::class, function ($container) {
            return new \Glueful\Security\RateLimiterDistributor(
                $container->get(CacheStore::class)
            );
        });

        // Edge cache service
        $container->singleton(\Glueful\Cache\EdgeCacheService::class, function ($container) {
            return new \Glueful\Cache\EdgeCacheService(
                $container->get(CacheStore::class)
            );
        });

        // Query cache service
        $container->singleton(\Glueful\Database\QueryCacheService::class, function ($container) {
            return new \Glueful\Database\QueryCacheService(
                $container->get(CacheStore::class)
            );
        });
    }

    public function boot(ContainerInterface $container): void
    {
        // Initialize services that need booting
        // This runs after all providers have registered their services
    }
}
