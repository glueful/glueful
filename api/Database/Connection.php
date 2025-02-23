<?php

namespace Glueful\Database;

use PDO;
use Glueful\Database\Driver\{MySQLDriver, PostgreSQLDriver, SQLiteDriver};
use Glueful\Database\Driver\DatabaseDriver;
use Exception;

/**
 * Database Connection Manager
 * 
 * Manages database connections with support for:
 * - Multiple database engines (MySQL, PostgreSQL, SQLite)
 * - Connection pooling
 * - Driver abstraction
 * - Configuration management
 * - Auto-reconnection
 * 
 * Provides a consistent interface for database connections across
 * different database engines while handling connection pooling
 * and configuration.
 */
class Connection
{
    /** @var array<string, PDO> Connection pool indexed by engine type */
    protected static array $instances = [];

    /** @var PDO Active database connection */
    protected PDO $pdo;

    /** @var DatabaseDriver Database-specific driver instance */
    protected DatabaseDriver $driver;

    /** @var array Database configuration parameters */
    protected array $config;

    /**
     * Initialize database connection
     * 
     * Creates new connection or returns existing pooled connection.
     * Resolves appropriate database driver based on engine type.
     * 
     * @param array $config Custom database configuration
     * @throws Exception If connection fails or engine unsupported
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $engine = $config['engine'] ?? config('database.engine');

        // Use existing connection if available (Pooling)
        if (isset(self::$instances[$engine])) {
            $this->pdo = self::$instances[$engine];
        } else {
            $this->pdo = $this->createPDOConnection($engine);
            self::$instances[$engine] = $this->pdo; // Store connection
        }

        $this->driver = $this->resolveDriver($engine);
    }

    /**
     * Create new PDO connection
     * 
     * Establishes connection to database with engine-specific options.
     * 
     * @param string $engine Database engine type
     * @return PDO Active database connection
     * @throws Exception If connection fails
     */
    private function createPDOConnection(string $engine): PDO
    {
        // Get engine-specific configuration
        $dbConfig = array_merge(
            config("database.{$engine}") ?? [],
            $this->config
        );

        // Set common PDO options
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Add engine-specific options
        if ($engine === 'mysql' && ($dbConfig['strict'] ?? true)) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET sql_mode='STRICT_ALL_TABLES'";
        }

        return new PDO(
            $this->buildDSN($engine, $dbConfig),
            $dbConfig['user'] ?? null,
            $dbConfig['pass'] ?? null,
            $options
        );
    }

    /**
     * Build database connection DSN
     * 
     * Creates connection string for specified database engine.
     * 
     * @param string $engine Database engine type
     * @param array $config Connection configuration
     * @return string Connection DSN
     * @throws Exception If engine unsupported
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
                'pgsql:host=%s;dbname=%s;port=%d;sslmode=%s;search_path=%s',
                $config['host'] ?? '127.0.0.1',
                $config['db'] ?? '',
                $config['port'] ?? 5432,
                $config['sslmode'] ?? 'prefer',
                $config['schema'] ?? 'public'
            ),
            'sqlite' => $this->prepareSQLiteDSN($config['primary']),
            default => throw new Exception("Unsupported database engine: {$engine}"),
        };
    }

    /**
     * Prepare SQLite database path
     * 
     * Ensures SQLite database directory exists and returns DSN.
     * 
     * @param string $dbPath Path to SQLite database file
     * @return string SQLite connection DSN
     */
    private function prepareSQLiteDSN(string $dbPath): string
    {
        @mkdir(dirname($dbPath), 0755, true); // Ensure directory exists
        return "sqlite:{$dbPath}";
    }

    /**
     * Resolve database driver
     * 
     * Creates appropriate driver instance for database engine.
     * 
     * @param string $engine Database engine type
     * @return DatabaseDriver Driver instance
     * @throws Exception If engine unsupported
     */
    private function resolveDriver(string $engine): DatabaseDriver
    {
        return match ($engine) {
            'mysql' => new MySQLDriver($this->pdo),
            'pgsql' => new PostgreSQLDriver($this->pdo),
            'sqlite' => new SQLiteDriver($this->pdo),
            default => throw new Exception("Unsupported database engine: {$engine}"),
        };
    }

    /**
     * Get active PDO connection
     * 
     * @return PDO Current database connection
     */
    public function getPDO(): PDO 
    {
        return $this->pdo;
    }

    /**
     * Get current database driver
     * 
     * @return DatabaseDriver Active database driver
     */
    public function getDriver(): DatabaseDriver 
    {
        return $this->driver;
    }
}