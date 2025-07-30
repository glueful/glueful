<?php

declare(strict_types=1);

namespace Glueful\Helpers;

use Glueful\Database\Connection;

/**
 * Database Connection Trait
 *
 * Provides shared database connection instance for controllers
 * to improve performance and reduce resource usage.
 *
 * This trait implements singleton pattern for database connections
 * across all controllers that use it, ensuring connection reuse
 * and eliminating redundant database connection overhead.
 *
 * Controllers can use the connection to create fluent queries:
 * $this->getConnection()->table('users')->where(['id' => 1])->get()
 *
 * @package Glueful\Helpers
 */
trait DatabaseConnectionTrait
{
    /** @var Connection|null Shared database connection across controllers */
    private static ?Connection $traitConnection = null;

    /**
     * Get shared database connection
     *
     * Returns the shared connection instance across all controllers,
     * creating it if needed. This ensures connection reuse and
     * improves performance by avoiding connection overhead.
     *
     * Use the connection to create fluent queries:
     * $this->getConnection()->table('users')->where(['id' => 1])->get()
     *
     * @return Connection The shared database connection
     */
    protected function getConnection(): Connection
    {
        return self::$traitConnection ??= new Connection();
    }
}
