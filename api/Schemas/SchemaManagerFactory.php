<?php

declare(strict_types=1);

namespace Glueful\Api\Schemas;

use Glueful\Api\Schemas\Drivers\{MySQLSchemaManager, SQLiteSchemaManager};
use Glueful\Api\Library\Utils;
use PDO;
use RuntimeException;

/**
 * Schema Manager Factory
 * 
 * Creates appropriate schema manager instance based on configured database engine.
 */
class SchemaManagerFactory
{
    /**
     * Get database connection based on engine type
     * 
     * @param string|null $engine Optional engine type (defaults to config)
     * @return PDO Active database connection
     * @throws RuntimeException If database engine is not supported
     */
    public static function getConnection(?string $engine = null): PDO
    {
        $engine = $engine ?? config('database.engine');
        
        return match($engine) {
            'mysql' => Utils::getMySQLConnection(),
            'sqlite' => Utils::getSQLiteConnection(),
            default => throw new RuntimeException("Unsupported database engine: $engine")
        };
    }

    /**
     * Create schema manager instance
     * 
     * @throws RuntimeException If database engine is not supported
     * @return SchemaManager Configured schema manager instance
     */
    public static function create(): SchemaManager
    {
        $engine = config('database.engine');
        $connection = self::getConnection($engine);

        return self::createForConnection($engine, $connection);
    }

    /**
     * Create schema manager for specific connection
     * 
     * @param string $engine Database engine type
     * @param PDO $connection Active database connection
     * @throws RuntimeException If database engine is not supported
     * @return SchemaManager Configured schema manager instance
     */
    public static function createForConnection(string $engine, PDO $connection): SchemaManager
    {
        return match($engine) {
            'mysql' => new MySQLSchemaManager($connection),
            'sqlite' => new SQLiteSchemaManager($connection),
            default => throw new RuntimeException("Unsupported database engine: $engine")
        };
    }
}
