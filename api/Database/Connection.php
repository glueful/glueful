<?php

namespace Glueful\Database;

use PDO;
use Glueful\Database\Driver\{MySQLDriver, PostgreSQLDriver, SQLiteDriver};
use Glueful\Database\Driver\DatabaseDriver;
use Exception;

class Connection
{
    protected static array $instances = [];

    protected PDO $pdo;
    protected DatabaseDriver $driver;
    protected array $config;

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

    private function prepareSQLiteDSN(string $dbPath): string
    {
        @mkdir(dirname($dbPath), 0755, true); // Ensure directory exists
        return "sqlite:{$dbPath}";
    }

    private function resolveDriver(string $engine): DatabaseDriver
    {
        return match ($engine) {
            'mysql' => new MySQLDriver($this->pdo),
            'pgsql' => new PostgreSQLDriver($this->pdo),
            'sqlite' => new SQLiteDriver($this->pdo),
            default => throw new Exception("Unsupported database engine: {$engine}"),
        };
    }

    public function getPDO(): PDO 
    {
        return $this->pdo;
    }

    public function getDriver(): DatabaseDriver 
    {
        return $this->driver;
    }
}