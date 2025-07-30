<?php

declare(strict_types=1);

namespace Glueful\Database\Execution\Interfaces;

use PDOStatement;

/**
 * Interface for processing database query results
 *
 * Provides methods to fetch and transform query results in various formats.
 */
interface ResultProcessorInterface
{
    /**
     * Fetch all rows from a statement
     *
     * @param PDOStatement $statement The executed statement
     * @return array Array of associative arrays
     */
    public function fetchAll(PDOStatement $statement): array;

    /**
     * Fetch a single row from a statement
     *
     * @param PDOStatement $statement The executed statement
     * @return array|null Associative array or null if no rows
     */
    public function fetchOne(PDOStatement $statement): ?array;

    /**
     * Fetch a single column value from the first row
     *
     * @param PDOStatement $statement The executed statement
     * @param int $columnNumber The column number (0-indexed)
     * @return mixed The column value or null
     */
    public function fetchColumn(PDOStatement $statement, int $columnNumber = 0): mixed;

    /**
     * Fetch results as key-value pairs
     *
     * @param PDOStatement $statement The executed statement
     * @param string|int $keyColumn The column to use as key
     * @param string|int $valueColumn The column to use as value
     * @return array Key-value pairs
     */
    public function fetchKeyValue(PDOStatement $statement, string|int $keyColumn, string|int $valueColumn): array;

    /**
     * Fetch results grouped by a column
     *
     * @param PDOStatement $statement The executed statement
     * @param string|int $groupColumn The column to group by
     * @return array Grouped results
     */
    public function fetchGrouped(PDOStatement $statement, string|int $groupColumn): array;

    /**
     * Process results with a callback
     *
     * @param PDOStatement $statement The executed statement
     * @param callable $callback The callback to process each row
     * @return array Processed results
     */
    public function fetchWithCallback(PDOStatement $statement, callable $callback): array;

    /**
     * Stream results one row at a time
     *
     * @param PDOStatement $statement The executed statement
     * @return \Generator Generator yielding rows
     */
    public function stream(PDOStatement $statement): \Generator;

    /**
     * Get the number of affected rows
     *
     * @param PDOStatement $statement The executed statement
     * @return int Number of affected rows
     */
    public function getAffectedRows(PDOStatement $statement): int;

    /**
     * Transform results to objects of a specific class
     *
     * @param PDOStatement $statement The executed statement
     * @param string $className The class name to instantiate
     * @param array $constructorArgs Constructor arguments
     * @return array Array of objects
     */
    public function fetchAsObjects(PDOStatement $statement, string $className, array $constructorArgs = []): array;
}
