<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\ServiceTags;
use Glueful\DI\Container;

class CoreServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        // Database services
        $container->register('database', \Glueful\Database\Connection::class)
            ->setPublic(true);

        $container->register(\Glueful\Database\QueryBuilder::class)
            ->setFactory([new Reference('database'), 'createQueryBuilder'])
            ->setPublic(true);

        $container->register(\Glueful\Database\Schema\SchemaManager::class)
            ->setFactory([new Reference('database'), 'getSchemaManager'])
            ->setPublic(true);

        // Cache services
        $container->register('cache.store', \Glueful\Cache\CacheStore::class)
            ->setFactory([\Glueful\Cache\CacheFactory::class, 'create'])
            ->setPublic(true)
            ->addTag(ServiceTags::CACHE_POOL);

        // Logger service
        $container->register('logger', \Psr\Log\LoggerInterface::class)
            ->setFactory([$this, 'createLogger'])
            ->setArguments([new Reference('service_container')])
            ->setPublic(true);

        // Add alias for PSR LoggerInterface
        $container->setAlias(\Psr\Log\LoggerInterface::class, 'logger')
            ->setPublic(true);

        // Security services
        $container->register(\Glueful\Security\RandomStringGenerator::class)
            ->setPublic(true);

        $container->register(\Glueful\Auth\TokenManager::class)
            ->setFactory([$this, 'createTokenManager'])
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        $container->register(\Glueful\Auth\AuthenticationManager::class)
            ->setPublic(true);

        // Request service
        $container->register('request', \Symfony\Component\HttpFoundation\Request::class)
            ->setFactory([\Symfony\Component\HttpFoundation\Request::class, 'createFromGlobals'])
            ->setPublic(true);

        // Permission services
        $container->register('permission.manager', \Glueful\Permissions\PermissionManager::class)
            ->setFactory([\Glueful\Permissions\PermissionManager::class, 'getInstance'])
            ->setPublic(true);

        $container->register(\Glueful\Permissions\PermissionCache::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        // Session services
        $container->register(\Glueful\Auth\SessionCacheManager::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        $container->register(\Glueful\Auth\SessionAnalytics::class)
            ->setArguments([
                new Reference('cache.store'),
                new Reference(\Glueful\Auth\SessionCacheManager::class)
            ])
            ->setPublic(true);

        $container->register(\Glueful\Auth\AuthenticationService::class)
            ->setArguments([
                null, // TokenStorageInterface will use default
                new Reference(\Glueful\Auth\SessionCacheManager::class)
            ])
            ->setPublic(true);

        // Performance services
        $container->register(\Glueful\Performance\MemoryManager::class)
            ->setPublic(true);

        // API services
        $container->register(\Glueful\Services\ApiMetricsService::class)
            ->setArguments([
                new Reference('cache.store'),
                new Reference('database'),
                new Reference(\Glueful\Database\Schema\SchemaManager::class)
            ])
            ->setPublic(true);

        $container->register(\Glueful\Services\HealthService::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        // Security services
        $container->register(\Glueful\Security\SecurityManager::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        // Cache services
        $container->register(\Glueful\Cache\CacheWarmupService::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        $distributedCacheDefinition = new Definition(\Glueful\Cache\DistributedCacheService::class);
        $distributedCacheDefinition->setArguments([
            new Reference('cache.store'),
            [] // Empty config array as default
        ]);
        $distributedCacheDefinition->setPublic(true);
        $container->setDefinition(\Glueful\Cache\DistributedCacheService::class, $distributedCacheDefinition);

        $container->register(\Glueful\Cache\EdgeCacheService::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        $container->register(\Glueful\Database\QueryCacheService::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);
    }

    public function boot(Container $container): void
    {
        // Post-compilation initialization if needed
    }

    public function getCompilerPasses(): array
    {
        return [
            // Core services don't need custom compiler passes
        ];
    }

    public function getName(): string
    {
        return 'core';
    }

    /**
     * Factory method for creating logger
     */
    public static function createLogger($container): \Psr\Log\LoggerInterface
    {
        // Framework logging should be optional and configurable
        $config = config('logging.framework', []);

        if (!($config['enabled'] ?? true)) {
            return new \Psr\Log\NullLogger();
        }

        if (env('APP_ENV') === 'testing') {
            return new \Psr\Log\NullLogger();
        }

        // Use framework-specific logging configuration
        $channelConfig = config('logging.channels.framework', []);
        $logLevel = \Monolog\Logger::toMonologLevel($config['level'] ?? 'info');

        if (env('APP_DEBUG', false)) {
            $logLevel = \Monolog\Logger::toMonologLevel('debug');
        }

        // Create framework logger with proper channel
        $logger = new \Monolog\Logger($config['channel'] ?? 'framework');

        // Set up framework log file with proper path
        $logPath = $channelConfig['path'] ?? (
            dirname(__DIR__, 3) . '/storage/logs/framework-' . date('Y-m-d') . '.log'
        );
        $handler = new \Monolog\Handler\StreamHandler($logPath, $logLevel);
        $logger->pushHandler($handler);

        return $logger;
    }

    /**
     * Factory method for creating token manager
     */
    public static function createTokenManager(\Glueful\Cache\CacheStore $cache): \Glueful\Auth\TokenManager
    {
        \Glueful\Auth\TokenManager::initialize($cache);
        return new \Glueful\Auth\TokenManager();
    }
}
