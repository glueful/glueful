<?php

declare(strict_types=1);

namespace Glueful\Database\Execution;

use Glueful\Database\Execution\Interfaces\ResultProcessorInterface;
use PDOStatement;
use PDO;

/**
 * Processes and transforms database query results
 *
 * This component handles fetching and transforming query results
 * in various formats to meet different application needs.
 */
class ResultProcessor implements ResultProcessorInterface
{
    /**
     * {@inheritdoc}
     */
    public function fetchAll(PDOStatement $statement): array
    {
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchOne(PDOStatement $statement): ?array
    {
        $result = $statement->fetch(PDO::FETCH_ASSOC);
        return $result === false ? null : $result;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchColumn(PDOStatement $statement, int $columnNumber = 0): mixed
    {
        $result = $statement->fetchColumn($columnNumber);
        return $result === false ? null : $result;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchKeyValue(PDOStatement $statement, string|int $keyColumn, string|int $valueColumn): array
    {
        $results = [];

        while ($row = $statement->fetch(PDO::FETCH_BOTH)) {
            $key = is_string($keyColumn) ? $row[$keyColumn] : $row[$keyColumn];
            $value = is_string($valueColumn) ? $row[$valueColumn] : $row[$valueColumn];
            $results[$key] = $value;
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchGrouped(PDOStatement $statement, string|int $groupColumn): array
    {
        $results = [];

        while ($row = $statement->fetch(PDO::FETCH_BOTH)) {
            $groupKey = is_string($groupColumn) ? $row[$groupColumn] : $row[$groupColumn];

            if (!isset($results[$groupKey])) {
                $results[$groupKey] = [];
            }

            $results[$groupKey][] = $row;
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchWithCallback(PDOStatement $statement, callable $callback): array
    {
        $results = [];

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $processed = $callback($row);
            if ($processed !== null) {
                $results[] = $processed;
            }
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function stream(PDOStatement $statement): \Generator
    {
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getAffectedRows(PDOStatement $statement): int
    {
        return $statement->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAsObjects(PDOStatement $statement, string $className, array $constructorArgs = []): array
    {
        if (!class_exists($className)) {
            throw new \InvalidArgumentException("Class {$className} does not exist");
        }

        if (empty($constructorArgs)) {
            return $statement->fetchAll(PDO::FETCH_CLASS, $className);
        }

        // When constructor args are provided, we need to fetch and instantiate manually
        $results = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $object = new $className(...$constructorArgs);

            // Populate object properties from row data
            foreach ($row as $property => $value) {
                if (property_exists($object, $property)) {
                    $object->$property = $value;
                }
            }

            $results[] = $object;
        }

        return $results;
    }

    /**
     * Fetch a single value (alias for fetchColumn with better naming)
     *
     * @param PDOStatement $statement The executed statement
     * @return mixed The value or null
     */
    public function fetchValue(PDOStatement $statement): mixed
    {
        return $this->fetchColumn($statement, 0);
    }

    /**
     * Count the total rows that would be returned without LIMIT
     *
     * @param PDOStatement $statement Statement from a COUNT(*) query
     * @return int The count
     */
    public function fetchCount(PDOStatement $statement): int
    {
        $count = $this->fetchColumn($statement, 0);
        return (int) $count;
    }

    /**
     * Fetch results as a flat array of a single column
     *
     * @param PDOStatement $statement The executed statement
     * @param string|int $column The column to extract
     * @return array Flat array of values
     */
    public function fetchFlatColumn(PDOStatement $statement, string|int $column = 0): array
    {
        $results = [];

        while ($row = $statement->fetch(PDO::FETCH_BOTH)) {
            $value = is_string($column) ? $row[$column] : $row[$column];
            $results[] = $value;
        }

        return $results;
    }

    /**
     * Check if any results exist
     *
     * @param PDOStatement $statement The executed statement
     * @return bool True if at least one row exists
     */
    public function hasResults(PDOStatement $statement): bool
    {
        return $this->fetchOne($statement) !== null;
    }
}
