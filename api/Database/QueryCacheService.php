<?php

declare(strict_types=1);

namespace Glueful\Database;

use Glueful\Cache\CacheStore;
use Glueful\Database\Attributes\CacheResult;
use Glueful\Helpers\CacheHelper;
use ReflectionMethod;

/**
 * Query Cache Service
 *
 * Provides intelligent caching for database query results.
 * Automatically handles caching, invalidation, and query analysis.
 */
class QueryCacheService
{
    /**
     * @var CacheStore Cache store instance
     */
    private CacheStore $cache;

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
     * @param CacheStore|null $cache Optional custom cache store instance
     */
    public function __construct(?CacheStore $cache = null)
    {
        // Set up cache - try provided instance or get from helper
        $this->cache = $cache ?? CacheHelper::createCacheInstance();

        if ($this->cache === null) {
            throw new \RuntimeException(
                'CacheStore is required for query cache service: Unable to create cache instance.'
            );
        }
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
            $allTags = ["query_cache:all"];
            foreach ($tables as $table) {
                $allTags[] = "query_cache:table:{$table}";
            }
            $this->cache->addTags($key, $allTags);

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
     * Cache the result of a repository method decorated with CacheResult attribute
     *
     * @param object $repository Repository instance
     * @param string $method Method name
     * @param array $args Method arguments
     * @return mixed Method result
     */
    public function cacheRepositoryMethod(object $repository, string $method, array $args = [])
    {
        $reflection = new ReflectionMethod($repository, $method);
        $attributes = $reflection->getAttributes(CacheResult::class);

        if (empty($attributes)) {
            // No CacheResult attribute, execute method directly
            return $reflection->invokeArgs($repository, $args);
        }

        // Get CacheResult attribute instance
        $cacheAttr = $attributes[0]->newInstance();

        // Generate a unique key for this method call
        $keyBase = $cacheAttr->keyPrefix ?: get_class($repository) . '::' . $method;
        $key = $this->generateMethodCacheKey($keyBase, $args);

        // Use the ttl from the attribute
        $ttl = $cacheAttr->ttl;

        // Cache or execute
        return $this->cache->remember($key, function () use ($reflection, $repository, $args, $key, $cacheAttr) {
            $result = $reflection->invokeArgs($repository, $args);

            // Apply custom tags if provided in the attribute
            if (!empty($cacheAttr->tags)) {
                $this->cache->addTags($key, $cacheAttr->tags);
            }

            return $result;
        }, $ttl);
    }

    /**
     * Generate a cache key for a repository method
     *
     * @param string $keyBase Base key (usually class::method)
     * @param array $args Method arguments
     * @return string Cache key
     */
    protected function generateMethodCacheKey(string $keyBase, array $args): string
    {
        // Create a deterministic hash of the arguments
        $argHash = md5(serialize($args));
        return "repo_method:{$keyBase}:{$argHash}";
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
