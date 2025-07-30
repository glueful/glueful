<?php

declare(strict_types=1);

namespace Glueful\Database\Execution\Interfaces;

use PDOStatement;

/**
 * QueryExecutor Interface
 *
 * Defines the contract for query execution functionality.
 * This interface ensures consistent query execution across
 * different implementations.
 */
interface QueryExecutorInterface
{
    /**
     * Execute a SELECT query and return all results
     */
    public function executeQuery(string $sql, array $bindings = []): array;

    /**
     * Execute a SELECT query and return first result
     */
    public function executeFirst(string $sql, array $bindings = []): ?array;

    /**
     * Execute a modification query (INSERT, UPDATE, DELETE)
     */
    public function executeModification(string $sql, array $bindings = []): int;

    /**
     * Execute a COUNT query
     */
    public function executeCount(string $sql, array $bindings = []): int;

    /**
     * Execute query and return PDO statement
     */
    public function executeStatement(string $sql, array $bindings = []): PDOStatement;

    /**
     * Check if caching is enabled for this executor
     */
    public function isCacheEnabled(): bool;

    /**
     * Enable query result caching
     */
    public function enableCache(?int $ttl = null): void;

    /**
     * Disable query result caching
     */
    public function disableCache(): void;

    /**
     * Set business purpose for queries
     */
    public function withPurpose(string $purpose): void;
}
