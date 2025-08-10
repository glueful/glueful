<?php

declare(strict_types=1);

namespace Glueful\Database;

use Glueful\Database\ConnectionPool;
use Glueful\Database\Driver\MySQLDriver;
use Glueful\Database\Driver\PostgreSQLDriver;
use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Driver\DatabaseDriver;

/**
 * ConnectionPoolManager
 *
 * Manages multiple connection pools for different database engines.
 * Provides centralized pool configuration, statistics, and lifecycle management.
 *
 * Features:
 * - Per-engine connection pool management
 * - Global and engine-specific configuration
 * - Comprehensive pool statistics
 * - Graceful shutdown handling
 * - Thread-safe pool creation
 *
 * @package Glueful\Database
 */
class ConnectionPoolManager
{
    /** @var array<string, ConnectionPool> Active connection pools by engine */
    private array $pools = [];

    /** @var array Global pooling configuration */
    private array $globalConfig;

    /** @var bool Whether manager has been shut down */
    private bool $isShutdown = false;

    /**
     * Initialize pool manager
     *
     * Loads global configuration and registers cleanup handlers.
     */
    public function __construct()
    {
        $this->globalConfig = config('database.pooling', []);

        // Register shutdown handler for cleanup
        register_shutdown_function([$this, 'shutdown']);
    }

    /**
     * Get connection pool for specified engine
     *
     * Creates pool if it doesn't exist using merged configuration.
     * Thread-safe pool creation with configuration inheritance.
     *
     * @param string $engine Database engine (mysql, pgsql, sqlite)
     * @return ConnectionPool Connection pool instance
     * @throws \Exception If pool creation fails
     */
    public function getPool(string $engine): ConnectionPool
    {
        if ($this->isShutdown) {
            throw new \RuntimeException('ConnectionPoolManager has been shut down');
        }

        if (!isset($this->pools[$engine])) {
            // Merge global defaults with engine-specific config
            $config = array_merge(
                $this->globalConfig['defaults'] ?? [],
                $this->globalConfig['engines'][$engine] ?? []
            );

            // Get database configuration for DSN building
            $dbConfig = config("database.{$engine}", []);

            // Build DSN and options
            $dsn = $this->buildDSN($engine, $dbConfig);
            $options = $this->buildPDOOptions($engine, $dbConfig);
            $driver = $this->resolveDriver($engine);

            // Extract credentials for non-SQLite engines
            $username = $engine !== 'sqlite' ? ($dbConfig['user'] ?? null) : null;
            $password = $engine !== 'sqlite' ? ($dbConfig['pass'] ?? null) : null;

            $this->pools[$engine] = new ConnectionPool(
                $config,
                $dsn,
                $username,
                $password,
                $options,
                $driver,
                $dbConfig
            );
        }

        return $this->pools[$engine];
    }

    /**
     * Get all active pools
     *
     * @return array<string, ConnectionPool>
     */
    public function getAllPools(): array
    {
        return $this->pools;
    }

    /**
     * Get comprehensive statistics for all pools
     *
     * @return array Pool statistics by engine
     */
    public function getStats(): array
    {
        $stats = [];
        foreach ($this->pools as $engine => $pool) {
            $stats[$engine] = $pool->getStats();
        }
        return $stats;
    }

    /**
     * Get aggregate statistics across all pools
     *
     * @return array Aggregate pool statistics
     */
    public function getAggregateStats(): array
    {
        $aggregate = [
            'total_pools' => count($this->pools),
            'total_active_connections' => 0,
            'total_idle_connections' => 0,
            'total_connections_created' => 0,
            'total_connections_destroyed' => 0,
            'total_acquisitions' => 0,
            'total_releases' => 0,
            'total_timeouts' => 0,
            'total_health_checks' => 0,
            'failed_health_checks' => 0
        ];

        foreach ($this->pools as $pool) {
            $stats = $pool->getStats();
            $aggregate['total_active_connections'] += $stats['active_connections'];
            $aggregate['total_idle_connections'] += $stats['idle_connections'];
            $aggregate['total_connections_created'] += $stats['total_created'];
            $aggregate['total_connections_destroyed'] += $stats['total_destroyed'];
            $aggregate['total_acquisitions'] += $stats['total_acquisitions'];
            $aggregate['total_releases'] += $stats['total_releases'];
            $aggregate['total_timeouts'] += $stats['total_timeouts'];
            $aggregate['total_health_checks'] += $stats['total_health_checks'];
            $aggregate['failed_health_checks'] += $stats['failed_health_checks'];
        }

        return $aggregate;
    }

    /**
     * Shutdown all pools and cleanup resources
     *
     * Called automatically on script shutdown or can be called manually.
     *
     * @return void
     */
    public function shutdown(): void
    {
        if ($this->isShutdown) {
            return;
        }

        foreach ($this->pools as $pool) {
            try {
                $pool->shutdown();
            } catch (\Exception $e) {
                // Log but don't throw during shutdown
                error_log('Error shutting down connection pool: ' . $e->getMessage());
            }
        }

        $this->pools = [];
        $this->isShutdown = true;
    }

    /**
     * Build database-specific DSN
     *
     * @param string $engine Database engine
     * @param array $config Database configuration
     * @return string DSN string
     * @throws \Exception For unsupported engines
     */
    private function buildDSN(string $engine, array $config): string
    {
        return match ($engine) {
            'mysql' => sprintf(
                'mysql:host=%s;dbname=%s;port=%d;charset=%s',
                $config['host'] ?? '127.0.0.1',
                $config['db'] ?? '',
                $config['port'] ?? 3306,
                $config['charset'] ?? 'utf8mb4'
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;dbname=%s;port=%d;sslmode=%s',
                $config['host'] ?? '127.0.0.1',
                $config['db'] ?? '',
                $config['port'] ?? 5432,
                $config['sslmode'] ?? 'prefer'
            ),
            'sqlite' => $this->prepareSQLiteDSN($config['primary'] ?? ':memory:'),
            default => throw new \Exception("Unsupported database engine: {$engine}"),
        };
    }

    /**
     * Build PDO options for engine
     *
     * @param string $engine Database engine
     * @param array $config Database configuration
     * @return array PDO options
     */
    private function buildPDOOptions(string $engine, array $config): array
    {
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Add engine-specific options
        if ($engine === 'mysql' && ($config['strict'] ?? true)) {
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET sql_mode='STRICT_ALL_TABLES'";
        }

        // Add user/password for non-SQLite engines
        if ($engine !== 'sqlite') {
            // Note: username and password are passed separately to ConnectionPool
            // and then to PDO constructor, not as options
        }

        return $options;
    }

    /**
     * Prepare SQLite DSN
     *
     * @param string $dbPath Database file path
     * @return string SQLite DSN
     */
    private function prepareSQLiteDSN(string $dbPath): string
    {
        if ($dbPath !== ':memory:') {
            @mkdir(dirname($dbPath), 0755, true);
        }
        return "sqlite:{$dbPath}";
    }

    /**
     * Resolve database driver for engine
     *
     * @param string $engine Database engine
     * @return DatabaseDriver Driver instance
     * @throws \Exception For unsupported engines
     */
    private function resolveDriver(string $engine): DatabaseDriver
    {
        return match ($engine) {
            'mysql' => new MySQLDriver(),
            'pgsql' => new PostgreSQLDriver(),
            'sqlite' => new SQLiteDriver(),
            default => throw new \Exception("Unsupported database engine: {$engine}"),
        };
    }

    /**
     * Check if manager has been shut down
     *
     * @return bool
     */
    public function isShutdown(): bool
    {
        return $this->isShutdown;
    }

    /**
     * Force cleanup of a specific pool
     *
     * @param string $engine Engine to cleanup
     * @return bool True if pool existed and was cleaned up
     */
    public function cleanupPool(string $engine): bool
    {
        if (isset($this->pools[$engine])) {
            $this->pools[$engine]->shutdown();
            unset($this->pools[$engine]);
            return true;
        }
        return false;
    }

    /**
     * Get pool health status
     *
     * @return array Health status by engine
     */
    public function getHealthStatus(): array
    {
        $health = [];

        foreach ($this->pools as $engine => $pool) {
            $stats = $pool->getStats();
            $health[$engine] = [
                'healthy' => $stats['failed_health_checks'] < ($stats['total_health_checks'] * 0.1),
                // < 10% failure rate
                'active_connections' => $stats['active_connections'],
                'idle_connections' => $stats['idle_connections'],
                'total_connections' => $stats['total_connections'],
                'health_check_failure_rate' => $stats['total_health_checks'] > 0
                    ? round(($stats['failed_health_checks'] / $stats['total_health_checks']) * 100, 2)
                    : 0,
                'timeout_rate' => $stats['total_acquisitions'] > 0
                    ? round(($stats['total_timeouts'] / $stats['total_acquisitions']) * 100, 2)
                    : 0
            ];
        }

        return $health;
    }
}
