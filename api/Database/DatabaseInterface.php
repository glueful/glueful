<?php

declare(strict_types=1);

namespace Glueful\Database;

use PDO;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\Driver\DatabaseDriver;

/**
 * Database Connection Interface
 *
 * Defines the contract for database connection implementations.
 * Used by services that need database access without coupling
 * to specific connection implementations.
 *
 * @package Glueful\Database
 */
interface DatabaseInterface
{
    /**
     * Get the PDO connection instance
     *
     * @return PDO The active database connection
     */
    public function getConnection(): PDO;

    /**
     * Get the PDO connection instance
     *
     * @return PDO The active database connection
     */
    public function getPDO(): PDO;

    /**
     * Get the schema manager for the current database
     *
     * @return SchemaManager The schema manager instance
     */
    public function getSchemaManager(): SchemaManager;

    /**
     * Get the database driver instance
     *
     * @return DatabaseDriver The driver instance
     */
    public function getDriver(): DatabaseDriver;

    /**
     * Get the database driver name
     *
     * @return string The driver name (mysql, pgsql, sqlite)
     */
    public function getDriverName(): string;
}
