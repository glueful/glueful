<?php

namespace Glueful\Database;

use PDO;
use Glueful\Database\Driver\MySQLDriver;
use Glueful\Database\Driver\PostgreSQLDriver;
use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\Schema\MySQLSchemaManager;
use Glueful\Database\Schema\PostgreSQLSchemaManager;
use Glueful\Database\Schema\SQLiteSchemaManager;
use Glueful\Database\ConnectionPoolManager;
use Glueful\Database\PooledConnection;
use Glueful\Exceptions\DatabaseException;
use Glueful\Exceptions\BusinessLogicException;

/**
 * Database Connection Manager
 *
 * Provides centralized database connection management with features:
 * - Connection pooling with lazy instantiation
 * - Multi-engine support (MySQL, PostgreSQL, SQLite)
 * - Automatic driver resolution
 * - Schema management integration
 * - Configuration-based initialization
 *
 * Design patterns:
 * - Singleton pool for connection reuse
 * - Factory method for driver creation
 * - Strategy pattern for database operations
 *
 * Requirements:
 * - PHP PDO extension
 * - Database-specific PDO drivers
 * - Valid configuration settings
 * - Appropriate database permissions
 */
class Connection
{
    /** @var array<string, PDO> Connection pool indexed by engine type */
    protected static array $instances = [];

    /** @var ConnectionPoolManager|null Pool manager instance */
    private static ?ConnectionPoolManager $poolManager = null;

    /** @var PDO Active database connection */
    protected PDO $pdo;

    /** @var DatabaseDriver Database-specific driver instance */
    protected DatabaseDriver $driver;

    /** @var SchemaManager|null Schema manager instance (initialized lazily) */
    protected ?SchemaManager $schemaManager = null;

    /** @var string Current database engine */
    protected string $engine;

    /** @var array Database configuration */
    protected array $config;

    /** @var ConnectionPool|null Active connection pool */
    private ?ConnectionPool $pool = null;

    /** @var PooledConnection|null Current pooled connection */
    private ?PooledConnection $pooledConnection = null;


    /**
     * Initialize database connection with optional pooling
     *
     * Creates or reuses database connections based on engine type.
     * Supports both legacy connection reuse and modern connection pooling.
     * Automatically resolves appropriate driver and schema manager.
     *
     * Connection lifecycle:
     * 1. Check if pooling is enabled
     * 2. Use connection pool if available
     * 3. Fall back to legacy connection reuse
     * 4. Initialize driver and schema manager
     *
     * @param array $config Optional configuration override
     * @throws \Glueful\Exceptions\DatabaseException On connection failure or invalid configuration
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->loadConfig(), $config);
        $this->engine = $this->config['engine'] ?? config('database.engine');

        // Initialize pool manager if pooling is enabled
        if ($this->config['pooling']['enabled'] ?? false) {
            self::$poolManager ??= new ConnectionPoolManager();
            $this->pool = self::$poolManager->getPool($this->engine);
        }

        $this->driver = $this->resolveDriver($this->engine);
        // Note: Schema manager is initialized lazily when first accessed
    }

    /**
     * Create PDO connection with engine-specific options
     *
     * Establishes database connection with:
     * - Engine-specific PDO options
     * - Error handling configuration
     * - Character set settings
     * - Strict mode (MySQL)
     * - SSL configuration (PostgreSQL)
     *
     * @param string $engine Target database engine
     * @return PDO Configured PDO instance
     * @throws \Glueful\Exceptions\DatabaseException On connection failure or invalid credentials
     */
    /**
     * Load database configuration
     *
     * @return array Complete database configuration
     */
    private function loadConfig(): array
    {
        return config('database', []);
    }

    private function createPDOConnection(string $engine): PDO
    {
        // Get engine-specific configuration
        $dbConfig = array_merge(
            config("database.{$engine}") ?? [],
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
     * Build database-specific connection DSN
     *
     * Generates connection string with support for:
     * MySQL:
     * - Host, port, database name
     * - Character set configuration
     * - SSL settings
     *
     * PostgreSQL:
     * - Host, port, database name
     * - Schema search path
     * - SSL mode configuration
     *
     * SQLite:
     * - File path handling
     * - Directory creation
     * - Journal mode settings
     *
     * @param string $engine Database engine type
     * @param array $config Engine-specific configuration
     * @return string Formatted DSN string
     * @throws \Glueful\Exceptions\BusinessLogicException For unsupported engines
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
            default => throw BusinessLogicException::operationNotAllowed(
                'database_connection',
                "Unsupported database engine: {$engine}"
            ),
        };
    }

    /**
     * Prepare SQLite database storage
     *
     * Ensures database file location is:
     * - Accessible
     * - Has proper permissions
     * - Parent directory exists
     *
     * @param string $dbPath Target database file path
     * @return string SQLite connection string
     * @throws \Glueful\Exceptions\BusinessLogicException If path is invalid or inaccessible
     */
    private function prepareSQLiteDSN(string $dbPath): string
    {
        @mkdir(dirname($dbPath), 0755, true); // Ensure directory exists
        return "sqlite:{$dbPath}";
    }

    /**
     * Factory method for database driver resolution
     *
     * Creates appropriate driver instance based on engine type.
     * Supports extensibility for additional engines.
     *
     * @param string $engine Target database engine
     * @return DatabaseDriver Initialized driver instance
     * @throws \Glueful\Exceptions\BusinessLogicException For unsupported engines
     */
    private function resolveDriver(string $engine): DatabaseDriver
    {
        return match ($engine) {
            'mysql' => new MySQLDriver(),
            'pgsql' => new PostgreSQLDriver(),
            'sqlite' => new SQLiteDriver(),
            default => throw BusinessLogicException::operationNotAllowed(
                'database_connection',
                "Unsupported database engine: {$engine}"
            ),
        };
    }

    /**
     * Factory method for schema manager resolution
     *
     * Creates database-specific schema manager instance.
     * Integrates with driver capabilities.
     *
     * @param string $engine Target database engine
     * @return SchemaManager Initialized schema manager
     * @throws \Glueful\Exceptions\BusinessLogicException For unsupported engines
     */
    private function resolveSchemaManager(string $engine): SchemaManager
    {
        return match ($engine) {
            'mysql' => new MySQLSchemaManager($this->getPDO()),
            'pgsql' => new PostgreSQLSchemaManager($this->getPDO()),
            'sqlite' => new SQLiteSchemaManager($this->getPDO()),
            default => throw BusinessLogicException::operationNotAllowed(
                'database_connection',
                "Unsupported database engine: {$engine}"
            ),
        };
    }

    /**
     * Access active schema manager instance
     *
     * Initializes schema manager lazily on first access to ensure
     * PDO connection is available.
     *
     * @return SchemaManager Current schema manager
     * @throws \Glueful\Exceptions\DatabaseException If schema manager initialization fails
     */
    public function getSchemaManager(): SchemaManager
    {
        if (!isset($this->schemaManager)) {
            $this->schemaManager = $this->resolveSchemaManager($this->engine);
        }
        return $this->schemaManager;
    }


    /**
     * Access active PDO connection
     *
     * Returns the underlying PDO instance from pooled connection if available,
     * otherwise falls back to legacy connection.
     *
     * @return PDO Active database connection
     * @throws \Glueful\Exceptions\DatabaseException If connection lost
     */
    public function getPDO(): PDO
    {
        // Use pooled connection if available
        if ($this->pool) {
            if (!$this->pooledConnection) {
                $this->pooledConnection = $this->pool->acquire();
            }
            return $this->pooledConnection->getPDO();
        }

        // Fallback to legacy connection reuse
        if (!isset($this->pdo)) {
            // Use existing connection if available (Legacy Pooling)
            if (isset(self::$instances[$this->engine])) {
                $this->pdo = self::$instances[$this->engine];
            } else {
                $this->pdo = $this->createPDOConnection($this->engine);
                self::$instances[$this->engine] = $this->pdo; // Store connection
            }
        }

        return $this->pdo;
    }

    /**
     * Access current database driver
     *
     * Returns engine-specific driver instance.
     *
     * @return DatabaseDriver Active database driver
     * @throws \Glueful\Exceptions\DatabaseException If driver not initialized
     */
    public function getDriver(): DatabaseDriver
    {
        return $this->driver;
    }

    /**
     * Get the name of the current database driver
     *
     * Returns the database engine name (mysql, pgsql, sqlite)
     *
     * @return string Database driver name
     */
    public function getDriverName(): string
    {
        return match (true) {
            $this->driver instanceof MySQLDriver => 'mysql',
            $this->driver instanceof PostgreSQLDriver => 'pgsql',
            $this->driver instanceof SQLiteDriver => 'sqlite',
            default => 'unknown'
        };
    }

    /**
     * Get connection pool manager
     *
     * @return ConnectionPoolManager|null Pool manager instance
     */
    public static function getPoolManager(): ?ConnectionPoolManager
    {
        return self::$poolManager;
    }

    /**
     * Destructor - Release pooled connection
     */
    public function __destruct()
    {
        // Release pooled connection
        if ($this->pooledConnection && $this->pool) {
            $this->pool->release($this->pooledConnection);
            $this->pooledConnection = null;
        }
    }
}
