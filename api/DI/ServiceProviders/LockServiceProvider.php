<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Interfaces\ServiceProviderInterface;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\Lock\LockManager;
use Glueful\Lock\LockManagerInterface;
use Glueful\Lock\Store\RedisLockStore;
use Glueful\Lock\Store\DatabaseLockStore;
use Glueful\Lock\Store\FileLockStore;
use Glueful\Database\DatabaseInterface;
use Psr\Log\LoggerInterface;

class LockServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerInterface $container): void
    {
        $container->singleton(LockManagerInterface::class, function ($container) {
            $config = $container->get('config')['lock'] ?? [];
            $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;

            $storeType = $config['default'] ?? 'file';
            $storeConfig = $config['stores'][$storeType] ?? [];

            $store = match ($storeType) {
                'redis' => $this->createRedisStore($container, $storeConfig),
                'database' => $this->createDatabaseStore($container, $storeConfig),
                'file' => $this->createFileStore($storeConfig),
                default => throw new \InvalidArgumentException("Unknown lock store type: {$storeType}")
            };

            $prefix = $config['prefix'] ?? 'glueful_lock_';

            return new LockManager($store, $logger, $prefix);
        });

        // Alias for convenience
        $container->alias('lock', LockManagerInterface::class);
    }

    private function createRedisStore($container, array $config): RedisLockStore
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

    private function createDatabaseStore($container, array $config): DatabaseLockStore
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

    private function createFileStore(array $config): FileLockStore
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

    public function boot(ContainerInterface $container): void
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
}
