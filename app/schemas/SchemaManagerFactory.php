<?php

declare(strict_types=1);

namespace Glueful\App\Database\Schemas;

use Glueful\Api\Database\Schemas\Drivers\MySQLSchemaManager;
use Glueful\Api\Database\Schemas\Drivers\SQLiteSchemaManager;
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
     * Create schema manager instance
     * 
     * @throws RuntimeException If database engine is not supported
     * @return SchemaManager Configured schema manager instance
     */
    public static function create(): SchemaManager
    {
        $engine = config('database.engine');
        $connection = match($engine) {
            'mysql' => Utils::getMySQLConnection(),
            'sqlite' => Utils::getSQLiteConnection(),
            default => throw new RuntimeException("Unsupported database engine: $engine")
        };

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
