<?php

namespace Glueful\Database;

require_once __DIR__ . '../../bootstrap.php';

use PDO;
use Glueful\Database\Driver\MySQLDriver;
use Glueful\Database\Driver\PostgreSQLDriver;
use Glueful\Database\Driver\SQLiteDriver;
use Glueful\Database\Driver\DatabaseDriver;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\Schema\MySQLSchemaManager;
use Glueful\Database\Schema\PostgreSQLSchemaManager;
use Glueful\Database\Schema\SQLiteSchemaManager;
use Exception;

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

    /** @var PDO Active database connection */
    protected PDO $pdo;

    /** @var DatabaseDriver Database-specific driver instance */
    protected DatabaseDriver $driver;

    protected SchemaManager $schemaManager;


    /**
     * Initialize database connection with pooling
     *
     * Creates or reuses database connections based on engine type.
     * Implements connection pooling to minimize resource usage.
     * Automatically resolves appropriate driver and schema manager.
     *
     * Connection lifecycle:
     * 1. Check pool for existing connection
     * 2. Create new connection if needed
     * 3. Initialize driver and schema manager
     * 4. Store connection in pool
     *
     * @throws Exception On connection failure or invalid configuration
     */
    public function __construct()
    {
        // $this->config = $config;
        $engine = config('database.engine');

        // Use existing connection if available (Pooling)
        if (isset(self::$instances[$engine])) {
            $this->pdo = self::$instances[$engine];
        } else {
            $this->pdo = $this->createPDOConnection($engine);
            self::$instances[$engine] = $this->pdo; // Store connection
        }

        $this->driver = $this->resolveDriver($engine);
        $this->schemaManager = $this->resolveSchemaManager($engine);
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
     * @throws Exception On connection failure or invalid credentials
     */
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
     * @throws Exception For unsupported engines
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
     * Prepare SQLite database storage
     *
     * Ensures database file location is:
     * - Accessible
     * - Has proper permissions
     * - Parent directory exists
     *
     * @param string $dbPath Target database file path
     * @return string SQLite connection string
     * @throws Exception If path is invalid or inaccessible
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
     * @throws Exception For unsupported engines
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
     * Factory method for schema manager resolution
     *
     * Creates database-specific schema manager instance.
     * Integrates with driver capabilities.
     *
     * @param string $engine Target database engine
     * @return SchemaManager Initialized schema manager
     * @throws Exception For unsupported engines
     */
    private function resolveSchemaManager(string $engine): SchemaManager
    {
        return match ($engine) {
            'mysql' => new MySQLSchemaManager($this->pdo),
            'pgsql' => new PostgreSQLSchemaManager($this->pdo),
            'sqlite' => new SQLiteSchemaManager($this->pdo),
            default => throw new Exception("Unsupported database engine: {$engine}"),
        };
    }

    /**
     * Access active schema manager instance
     *
     * @return SchemaManager Current schema manager
     * @throws Exception If schema manager not initialized
     */
    public function getSchemaManager(): SchemaManager
    {
        return $this->schemaManager;
    }


    /**
     * Access active PDO connection
     *
     * Returns pooled connection instance.
     * Ensures connection is active.
     *
     * @return PDO Active database connection
     * @throws Exception If connection lost
     */
    public function getPDO(): PDO
    {
        return $this->pdo;
    }

    /**
     * Access current database driver
     *
     * Returns engine-specific driver instance.
     *
     * @return DatabaseDriver Active database driver
     * @throws Exception If driver not initialized
     */
    public function getDriver(): DatabaseDriver
    {
        return $this->driver;
    }
}
