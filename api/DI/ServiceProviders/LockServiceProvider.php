<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\Container;
use Glueful\Lock\LockManager;
use Glueful\Lock\LockManagerInterface;
use Glueful\Lock\Store\RedisLockStore;
use Glueful\Lock\Store\DatabaseLockStore;
use Glueful\Lock\Store\FileLockStore;
use Glueful\Database\DatabaseInterface;

class LockServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        $container->register(LockManagerInterface::class)
            ->setFactory([$this, 'createLockManager'])
            ->setArguments([new Reference('service_container')])
            ->setPublic(true);

        // Alias for convenience
        $container->setAlias('lock', LockManagerInterface::class);
    }

    protected static function createRedisStore($container, array $config): RedisLockStore
    {
        if (!$container->has(\Redis::class)) {
            throw new \RuntimeException('Redis service not found in container. Please ensure Redis is configured.');
        }

        $redis = $container->get(\Redis::class);

        return new RedisLockStore($redis, [
            'prefix' => $config['prefix'] ?? 'glueful_lock_',
            'ttl' => $config['ttl'] ?? 300,
        ]);
    }

    protected static function createDatabaseStore($container, array $config): DatabaseLockStore
    {
        if (!$container->has(DatabaseInterface::class)) {
            throw new \RuntimeException('Database service not found in container.');
        }

        $database = $container->get(DatabaseInterface::class);

        return new DatabaseLockStore($database, [
            'table' => $config['table'] ?? 'locks',
            'id_col' => $config['id_col'] ?? 'key_id',
            'token_col' => $config['token_col'] ?? 'token',
            'expiration_col' => $config['expiration_col'] ?? 'expiration',
        ]);
    }

    protected static function createFileStore(array $config): FileLockStore
    {
        $path = $config['path'] ?? null;

        if ($path && !str_starts_with($path, '/')) {
            // Convert relative path to absolute using storage directory
            $storagePath = config('app.paths.storage_path', __DIR__ . '/../../../storage');
            $path = rtrim($storagePath, '/') . '/' . ltrim($path, '/');
        }

        return new FileLockStore($path, [
            'prefix' => $config['prefix'] ?? 'lock_',
            'extension' => $config['extension'] ?? '.lock',
        ]);
    }

    public function boot(Container $container): void
    {
        // Create locks directory if using file store
        $config = config('lock', []);

        if (($config['default'] ?? 'file') === 'file') {
            $storePath = $config['stores']['file']['path'] ?? 'framework/locks';

            if (!str_starts_with($storePath, '/')) {
                // Convert relative path to absolute using storage directory
                $storagePath = config('app.paths.storage_path', __DIR__ . '/../../../storage');
                $fullPath = rtrim($storagePath, '/') . '/' . ltrim($storePath, '/');

                if (!is_dir($fullPath)) {
                    mkdir($fullPath, 0755, true);
                }
            }
        }
    }

    /**
     * Get compiler passes for lock services
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
        return 'lock';
    }

    /**
     * Factory method for creating LockManager
     */
    public static function createLockManager($container): LockManagerInterface
    {
        $config = config('lock', []);
        $logger = $container->has('logger') ? $container->get('logger') : null;

        $storeType = $config['default'] ?? 'file';
        $storeConfig = $config['stores'][$storeType] ?? [];

        $store = match ($storeType) {
            'redis' => static::createRedisStore($container, $storeConfig),
            'database' => static::createDatabaseStore($container, $storeConfig),
            'file' => static::createFileStore($storeConfig),
            default => throw new \InvalidArgumentException("Unknown lock store type: {$storeType}")
        };

        $prefix = $config['prefix'] ?? 'glueful_lock_';

        return new LockManager($store, $logger, $prefix);
    }
}
