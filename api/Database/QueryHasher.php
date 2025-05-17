<?php

declare(strict_types=1);

namespace Glueful\Database;

/**
 * Query Hasher
 *
 * Generates deterministic cache keys for database queries.
 * Ensures consistent key generation for identical queries and parameters.
 */
class QueryHasher
{
    /**
     * Generate a normalized hash key for a query and its parameters
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return string Hash key
     */
    public function hash(string $query, array $params): string
    {
        // Normalize the query by removing extra whitespace
        $normalizedQuery = $this->normalizeQuery($query);

        // Combine query and serialized parameters, then hash
        $hashSource = $normalizedQuery . '|' . $this->serializeParams($params);

        // Use SHA-256 for a strong, consistent hash
        return hash('sha256', $hashSource);
    }

    /**
     * Create a composite cache key including db name and connection info
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @param string $connection Optional connection name
     * @param string $dbName Optional database name
     * @return string Composite cache key
     */
    public function generateCacheKey(
        string $query,
        array $params,
        string $connection = '',
        string $dbName = ''
    ): string {
        // Get base hash
        $hash = $this->hash($query, $params);

        // Determine connection and db name if not provided
        $connection = $connection ?: config('database.engine', 'mysql');
        $dbName = $dbName ?: $this->getDatabaseName($connection);

        // Combine all components into a cache key with query type prefix
        $prefix = 'query_cache';
        $queryType = $this->determineQueryType($query);

        return sprintf(
            '%s:%s:%s:%s',
            $prefix,
            $queryType,
            $this->escapeKeyComponent($dbName),
            $hash
        );
    }

    /**
     * Normalize a SQL query by removing extra whitespace
     *
     * @param string $query SQL query
     * @return string Normalized query
     */
    protected function normalizeQuery(string $query): string
    {
        // Remove comments
        $query = preg_replace('/--.*$/m', '', $query);
        $query = preg_replace('!/\*.*?\*/!s', '', $query);

        // Normalize whitespace
        $query = preg_replace('/\s+/', ' ', trim($query));

        return $query;
    }

    /**
     * Serialize parameters into a consistent string representation
     *
     * @param array $params Query parameters
     * @return string Serialized parameters
     */
    protected function serializeParams(array $params): string
    {
        // Sort array by key to ensure consistent ordering
        ksort($params);

        // Use JSON encoding to handle various data types consistently
        return json_encode($params, JSON_UNESCAPED_SLASHES);
    }

    /**
     * Determine the type of query (select, insert, update, delete)
     *
     * @param string $query SQL query
     * @return string Query type
     */
    protected function determineQueryType(string $query): string
    {
        $query = trim(strtolower($query));

        if (strpos($query, 'select') === 0) {
            return 'select';
        } elseif (strpos($query, 'insert') === 0) {
            return 'insert';
        } elseif (strpos($query, 'update') === 0) {
            return 'update';
        } elseif (strpos($query, 'delete') === 0) {
            return 'delete';
        }

        return 'other';
    }

    /**
     * Get database name from configuration
     *
     * @param string $connection Connection name
     * @return string Database name
     */
    protected function getDatabaseName(string $connection): string
    {
        if ($connection === 'sqlite') {
            // For SQLite, use the database file path
            return config('database.sqlite.primary', 'sqlite');
        }

        // For MySQL and PostgreSQL, use the database name
        return config("database.{$connection}.db", $connection);
    }

    /**
     * Escape key component for use in cache keys
     *
     * @param string $component Key component
     * @return string Escaped component
     */
    protected function escapeKeyComponent(string $component): string
    {
        // Replace characters that might cause issues in cache keys
        return str_replace(['/', '\\', ':', '.'], '_', $component);
    }
}
