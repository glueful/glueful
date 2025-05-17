<?php

declare(strict_types=1);

namespace Glueful\Database;

use Glueful\Cache\CacheEngine;

/**
 * Query Cache Service
 *
 * Provides intelligent caching for database query results.
 * Automatically handles caching, invalidation, and query analysis.
 */
class QueryCacheService
{
    /**
     * @var CacheEngine Cache engine instance
     */
    private $cache;

    /**
     * @var QueryHasher Query hash generator
     */
    private $queryHasher;

    /**
     * @var bool Whether caching is enabled
     */
    private $enabled;

    /**
     * @var int Default time-to-live for cached results
     */
    private $defaultTtl;

    /**
     * Constructor
     *
     * @param CacheEngine|null $cache Optional custom cache engine instance
     */
    public function __construct(?CacheEngine $cache = null)
    {
        $this->cache = $cache ?? new CacheEngine();
        $this->queryHasher = new QueryHasher();
        $this->enabled = config('database.query_cache.enabled', true);
        $this->defaultTtl = config('database.query_cache.default_ttl', 3600);
    }

    /**
     * Get cached query result or execute and cache
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @param \Closure $executor Function to execute if cache miss
     * @param int|null $ttl Cache TTL in seconds
     * @return mixed Query result
     */
    public function getOrExecute(string $query, array $params, \Closure $executor, ?int $ttl = null)
    {
        if (!$this->enabled || !$this->isCacheable($query)) {
            return $executor();
        }

        $ttl = $ttl ?? $this->defaultTtl;
        $key = $this->generateCacheKey($query, $params);

        return $this->cache->remember($key, function () use ($executor, $query, $key) {
            $result = $executor();

            // Associate the cache entry with affected tables for invalidation
            $tables = $this->extractTablesFromQuery($query);
            foreach ($tables as $table) {
                $this->cache->addTags($key, ["query_cache:table:{$table}"]);
            }
            $this->cache->addTags($key, "query_cache:all");

            return $result;
        }, $ttl);
    }

    /**
     * Invalidate cached results based on table name
     *
     * @param string $tableName Table name to invalidate
     * @return bool True if invalidation succeeded
     */
    public function invalidateTable(string $tableName)
    {
        $tags = [
            "query_cache:table:{$tableName}",
            "query_cache:all"
        ];

        return $this->cache->invalidateTags($tags);
    }

    /**
     * Check if a query is cacheable
     *
     * @param string $query SQL query
     * @return bool True if query can be cached
     */
    protected function isCacheable(string $query): bool
    {
        // Only cache SELECT queries by default
        if (!preg_match('/^\s*SELECT\b/i', $query)) {
            return false;
        }

        // Skip queries with FOR UPDATE or other locking clauses
        if (preg_match('/FOR\s+UPDATE\b/i', $query)) {
            return false;
        }

        // Check against exclude patterns from config
        $excludePatterns = config('database.query_cache.exclude_patterns', []);
        foreach ($excludePatterns as $pattern) {
            if (preg_match($pattern, $query)) {
                return false;
            }
        }

        // Skip queries on excluded tables
        $excludeTables = config('database.query_cache.exclude_tables', []);
        $tables = $this->extractTablesFromQuery($query);

        foreach ($tables as $table) {
            if (in_array($table, $excludeTables)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate cache key for a query
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @return string Cache key
     */
    protected function generateCacheKey(string $query, array $params): string
    {
        return $this->queryHasher->generateCacheKey($query, $params);
    }

    /**
     * Extract table names from a SQL query
     *
     * @param string $query SQL query
     * @return array List of table names
     */
    protected function extractTablesFromQuery(string $query): array
    {
        $tables = [];
        $query = ' ' . preg_replace('/\s+/', ' ', trim($query)) . ' ';

        // Simple extraction for common patterns
        // FROM table
        if (preg_match_all('/\bFROM\s+`?(\w+)`?/i', $query, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // JOIN table
        if (preg_match_all('/\bJOIN\s+`?(\w+)`?/i', $query, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // UPDATE table
        if (preg_match_all('/\bUPDATE\s+`?(\w+)`?/i', $query, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // INSERT INTO table
        if (preg_match_all('/\bINSERT\s+INTO\s+`?(\w+)`?/i', $query, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        // DELETE FROM table
        if (preg_match_all('/\bDELETE\s+FROM\s+`?(\w+)`?/i', $query, $matches)) {
            $tables = array_merge($tables, $matches[1]);
        }

        return array_unique($tables);
    }
}
