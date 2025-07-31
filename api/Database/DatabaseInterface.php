<?php

declare(strict_types=1);

namespace Glueful\Database;

use PDO;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
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
    public function getPDO(): PDO;

    /**
     * Get the schema builder for the current database
     *
     * @return SchemaBuilderInterface The schema builder instance
     */
    public function getSchemaBuilder(): SchemaBuilderInterface;

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
