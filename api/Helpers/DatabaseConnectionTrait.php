<?php

declare(strict_types=1);

namespace Glueful\Helpers;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * Database Connection Trait
 *
 * Provides shared database connection and query builder instances
 * for controllers to improve performance and reduce resource usage.
 *
 * This trait implements singleton pattern for database connections
 * across all controllers that use it, ensuring connection reuse
 * and eliminating redundant database connection overhead.
 *
 * @package Glueful\Helpers
 */
trait DatabaseConnectionTrait
{
    /** @var Connection|null Shared database connection across controllers */
    private static ?Connection $traitConnection = null;

    /** @var QueryBuilder|null Shared query builder across controllers */
    private static ?QueryBuilder $traitQueryBuilder = null;

    /**
     * Get shared database connection
     *
     * Returns the shared connection instance across all controllers,
     * creating it if needed. This ensures connection reuse and
     * improves performance by avoiding connection overhead.
     *
     * @return Connection The shared database connection
     */
    protected function getConnection(): Connection
    {
        return self::$traitConnection ??= new Connection();
    }

    /**
     * Get shared query builder
     *
     * Returns the shared query builder instance across all controllers,
     * creating it if needed. This ensures query builder reuse and
     * improves performance by avoiding initialization overhead.
     *
     * @return QueryBuilder The shared query builder
     */
    protected function getQueryBuilder(): QueryBuilder
    {
        if (!self::$traitQueryBuilder) {
            $conn = $this->getConnection();
            self::$traitQueryBuilder = new QueryBuilder($conn->getPDO(), $conn->getDriver());
        }
        return self::$traitQueryBuilder;
    }
}
