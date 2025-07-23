<?php

declare(strict_types=1);

namespace Glueful\Cache\Drivers;

use InvalidArgumentException;
use Glueful\Security\SecureSerializer;
use Psr\SimpleCache\InvalidArgumentException as PSRInvalidArgumentException;
use Glueful\Exceptions\CacheException;
use Glueful\Cache\CacheStore;
use Glueful\Services\FileManager;
use Glueful\Services\FileFinder;

/**
 * File Cache Driver
 *
 * Provides file-based caching as a fallback when Redis/Memcached are unavailable.
 * Implements CacheStore with file system storage.
 */
class FileCacheDriver implements CacheStore
{
    /** @var string Base directory for cache files */
    private string $directory;

    /** @var SecureSerializer Secure serialization service */
    private SecureSerializer $serializer;

    /** @var FileManager File operations manager */
    private FileManager $fileManager;

    /** @var FileFinder File finder service */
    private FileFinder $fileFinder;

    /** @var string File extension for cache files */
    private const FILE_EXT = '.cache';

    /** @var string Extension for metadata files */
    private const META_EXT = '.meta';

    /** @var string Extension for sorted set files */
    private const ZSET_EXT = '.zset';

    /**
     * Constructor - requires FileManager and FileFinder services
     *
     * @param string $directory Cache directory path
     * @param FileManager $fileManager File manager service
     * @param FileFinder $fileFinder File finder service
     * @throws InvalidArgumentException If directory is invalid
     */
    public function __construct(string $directory, FileManager $fileManager, FileFinder $fileFinder)
    {
        $this->directory = rtrim($directory, '/') . '/';
        $this->serializer = SecureSerializer::forCache();
        $this->fileManager = $fileManager;
        $this->fileFinder = $fileFinder;

        // Use FileManager to create directory if needed
        if (!is_dir($this->directory)) {
            try {
                $this->fileManager->createDirectory($this->directory);
            } catch (\Exception $e) {
                throw new InvalidArgumentException("Cannot create cache directory: {$this->directory}", 0, $e);
            }
        }

        if (!is_writable($this->directory)) {
            throw new InvalidArgumentException("Cache directory not writable: {$this->directory}");
        }
    }

    /**
     * Get cache file path
     *
     * @param string $key Cache key
     * @return string Absolute file path
     */
    private function getFilePath(string $key, string $extension = self::FILE_EXT): string
    {
        return $this->directory . md5($key) . $extension;
    }

    /**
     * Save data to file using FileManager for atomic operations
     *
     * @param string $path File path
     * @param mixed $data Data to save
     * @return bool Success
     */
    private function saveToFile(string $path, mixed $data): bool
    {
        try {
            $serialized = $this->serializer->serialize($data);
            return $this->fileManager->writeFile($path, $serialized);
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Load data from file using FileManager
     *
     * @param string $path File path
     * @return mixed Loaded data or null
     */
    private function loadFromFile(string $path): mixed
    {
        try {
            if (!$this->fileManager->exists($path)) {
                return null;
            }

            $data = $this->fileManager->readFile($path);
            return $this->serializer->unserialize($data);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get cached value (PSR-16 compatible)
     *
     * @param string $key Cache key
     * @param mixed $default Default value if key not found
     * @return mixed Cached value or default if not found
     * @throws PSRInvalidArgumentException If key is invalid
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);
        $filePath = $this->getFilePath($key);
        $metaPath = $this->getFilePath($key, self::META_EXT);

        if (!$this->fileManager->exists($filePath) || !$this->fileManager->exists($metaPath)) {
            return $default;
        }

        // Check if expired
        $meta = $this->loadFromFile($metaPath);
        if ($meta === null || (isset($meta['expires']) && $meta['expires'] > 0 && $meta['expires'] < time())) {
            // Clean up expired files
            $this->fileManager->remove($filePath);
            $this->fileManager->remove($metaPath);
            return $default;
        }

        $value = $this->loadFromFile($filePath);
        return $value !== null ? $value : $default;
    }

    /**
     * Store value in cache (PSR-16 compatible)
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param null|int|\DateInterval $ttl Time to live
     * @return bool True if stored successfully
     * @throws PSRInvalidArgumentException If key is invalid
     */
    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->validateKey($key);
        $filePath = $this->getFilePath($key);
        $metaPath = $this->getFilePath($key, self::META_EXT);

        $seconds = $this->normalizeTtl($ttl);
        $meta = [
            'created' => time(),
            'expires' => ($seconds && $seconds > 0) ? time() + $seconds : 0,
            'ttl' => $seconds ?? 0
        ];

        if (!$this->saveToFile($metaPath, $meta)) {
            return false;
        }

        return $this->saveToFile($filePath, $value);
    }

    /**
     * Set value only if key does not exist (atomic operation)
     *
     * Uses file locking to ensure atomicity.
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds
     * @return bool True if key was set (didn't exist), false if key already exists
     */
    public function setNx(string $key, mixed $value, int $ttl = 3600): bool
    {
        $filePath = $this->getFilePath($key);
        $metaPath = $this->getFilePath($key, self::META_EXT);
        $lockPath = $filePath . '.lock';

        // Use file locking for atomicity
        $lockFile = fopen($lockPath, 'c');
        if (!$lockFile) {
            return false;
        }

        try {
            // Acquire exclusive lock
            if (!flock($lockFile, LOCK_EX)) {
                return false;
            }

            // Check if key already exists (and is not expired)
            if ($this->fileManager->exists($filePath) && $this->fileManager->exists($metaPath)) {
                $meta = $this->loadFromFile($metaPath);
                if ($meta !== null && (!isset($meta['expires']) || $meta['expires'] >= time())) {
                    // Key exists and is not expired
                    return false;
                }
            }

            // Key doesn't exist or is expired, set it
            $meta = [
                'created' => time(),
                'expires' => ($ttl > 0) ? time() + $ttl : 0,
                'ttl' => $ttl
            ];

            if (!$this->saveToFile($metaPath, $meta)) {
                return false;
            }

            return $this->saveToFile($filePath, $value);
        } finally {
            // Always release lock and clean up
            flock($lockFile, LOCK_UN);
            fclose($lockFile);
            $this->fileManager->remove($lockPath);
        }
    }

    /**
     * Get multiple cached values
     *
     * @param array $keys Array of cache keys
     * @return array Indexed array of values (same order as keys, null for missing keys)
     */
    public function mget(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $result[] = $this->get($key);
        }

        return $result;
    }

    /**
     * Store multiple values in cache
     *
     * @param array $values Associative array of key => value pairs
     * @param int $ttl Time to live in seconds
     * @return bool True if all values stored successfully
     */
    public function mset(array $values, int $ttl = 3600): bool
    {
        $success = true;

        foreach ($values as $key => $value) {
            $success = $success && $this->set($key, $value, $ttl);
        }

        return $success;
    }

    /**
     * Delete cached value (PSR-16 compatible)
     *
     * @param string $key Cache key
     * @return bool True if deleted successfully
     * @throws PSRInvalidArgumentException If key is invalid
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);
        return $this->del($key);
    }

    /**
     * Increment numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to increment by (default: 1)
     * @return int New value after increment
     */
    public function increment(string $key, int $value = 1): int
    {
        $currentValue = $this->get($key, 0);

        if (!is_numeric($currentValue)) {
            $currentValue = 0;
        }

        $newValue = (int) $currentValue + $value;

        // Preserve existing TTL
        $metaPath = $this->getFilePath($key, self::META_EXT);
        $meta = $this->loadFromFile($metaPath);
        $ttl = $meta['ttl'] ?? 3600;

        $this->set($key, $newValue, $ttl);
        return $newValue;
    }

    /**
     * Decrement numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to decrement by (default: 1)
     * @return int New value after decrement
     */
    public function decrement(string $key, int $value = 1): int
    {
        return $this->increment($key, -$value);
    }

    /**
     * Get remaining TTL
     *
     * @param string $key Cache key
     * @return int Remaining time in seconds
     */
    public function ttl(string $key): int
    {
        $metaPath = $this->getFilePath($key, self::META_EXT);

        if (!$this->fileManager->exists($metaPath)) {
            return -2; // Key doesn't exist
        }

        $meta = $this->loadFromFile($metaPath);
        if ($meta === null) {
            return -2;
        }

        if ($meta['expires'] === 0) {
            return -1; // No expiry
        }

        $ttl = $meta['expires'] - time();
        return $ttl > 0 ? $ttl : -2;
    }

    /**
     * Clear all cached values (PSR-16 compatible)
     *
     * @return bool True if cache cleared successfully
     */
    public function clear(): bool
    {
        try {
            $finder = $this->fileFinder->createFinder();
            $files = $finder->files()->in($this->directory);

            $success = true;
            foreach ($files as $file) {
                $success = $success && $this->fileManager->remove($file->getPathname());
            }

            return $success;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Clear all cached values (alias for PSR-16 clear() for backward compatibility)
     *
     * @return bool True if cache cleared successfully
     */
    public function flush(): bool
    {
        return $this->clear();
    }

    /**
     * Add to sorted set
     *
     * @param string $key Set key
     * @param array $scoreValues Score-value pairs
     * @return bool True if added successfully
     */
    public function zadd(string $key, array $scoreValues): bool
    {
        $zsetPath = $this->getFilePath($key, self::ZSET_EXT);
        $metaPath = $this->getFilePath($key, self::META_EXT);

        // Load existing set or create new one
        $zset = $this->loadFromFile($zsetPath) ?: [];

        // Add or update elements
        foreach ($scoreValues as $score => $value) {
            $zset[$value] = $score;
        }

        // Save metadata if it doesn't exist
        if (!$this->fileManager->exists($metaPath)) {
            $meta = [
                'created' => time(),
                'expires' => 0, // No expiry by default for sets
                'ttl' => 0
            ];
            if (!$this->saveToFile($metaPath, $meta)) {
                return false;
            }
        }

        return $this->saveToFile($zsetPath, $zset);
    }

    /**
     * Remove set members by score
     *
     * @param string $key Set key
     * @param string $min Minimum score
     * @param string $max Maximum score
     * @return int Number of removed members
     */
    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        $zsetPath = $this->getFilePath($key, self::ZSET_EXT);

        if (!$this->fileManager->exists($zsetPath)) {
            return 0;
        }

        $zset = $this->loadFromFile($zsetPath) ?: [];
        $count = 0;

        foreach ($zset as $value => $score) {
            if ($score >= $min && $score <= $max) {
                unset($zset[$value]);
                $count++;
            }
        }

        if ($count > 0) {
            $this->saveToFile($zsetPath, $zset);
        }

        return $count;
    }

    /**
     * Get set cardinality
     *
     * @param string $key Set key
     * @return int Number of members
     */
    public function zcard(string $key): int
    {
        $zsetPath = $this->getFilePath($key, self::ZSET_EXT);

        if (!$this->fileManager->exists($zsetPath)) {
            return 0;
        }

        $zset = $this->loadFromFile($zsetPath) ?: [];
        return count($zset);
    }

    /**
     * Get set range
     *
     * @param string $key Set key
     * @param int $start Start index
     * @param int $stop End index
     * @return array Range of members
     */
    public function zrange(string $key, int $start, int $stop): array
    {
        $zsetPath = $this->getFilePath($key, self::ZSET_EXT);

        if (!$this->fileManager->exists($zsetPath)) {
            return [];
        }

        $zset = $this->loadFromFile($zsetPath) ?: [];

        // Sort by score
        asort($zset);

        // Get keys (values)
        $values = array_keys($zset);

        // Handle negative indices
        if ($start < 0) {
            $start = count($values) + $start;
        }
        if ($stop < 0) {
            $stop = count($values) + $stop;
        }

        // Ensure indices are valid
        $start = max(0, $start);
        $stop = min(count($values) - 1, $stop);

        if ($start > $stop || $start >= count($values)) {
            return [];
        }

        return array_slice($values, $start, $stop - $start + 1);
    }

    /**
     * Set key expiration
     *
     * @param string $key Cache key
     * @param int $seconds Time until expiration
     * @return bool True if expiration set
     */
    public function expire(string $key, int $seconds): bool
    {
        $metaPath = $this->getFilePath($key, self::META_EXT);

        if (!$this->fileManager->exists($metaPath)) {
            return false;
        }

        $meta = $this->loadFromFile($metaPath);
        if ($meta === null) {
            return false;
        }

        $meta['expires'] = time() + $seconds;
        $meta['ttl'] = $seconds;

        return $this->saveToFile($metaPath, $meta);
    }

    /**
     * Delete key
     *
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    public function del(string $key): bool
    {
        $filePath = $this->getFilePath($key);
        $metaPath = $this->getFilePath($key, self::META_EXT);
        $zsetPath = $this->getFilePath($key, self::ZSET_EXT);

        $success = true;

        if ($this->fileManager->exists($filePath)) {
            $success = $this->fileManager->remove($filePath);
        }

        if ($this->fileManager->exists($metaPath) && $success) {
            $success = $this->fileManager->remove($metaPath);
        }

        if ($this->fileManager->exists($zsetPath)) {
            $success = $success && $this->fileManager->remove($zsetPath);
        }

        return $success;
    }

    /**
     * Delete keys matching a pattern
     *
     * Scans the cache directory for files matching the pattern.
     *
     * @param string $pattern Pattern to match (supports wildcards *)
     * @return bool True if deletion successful
     */
    public function deletePattern(string $pattern): bool
    {
        try {
            // Convert cache key pattern to file pattern
            $filePattern = str_replace(['*', '/', '\\'], ['*', '_', '_'], $pattern) . '.*';

            $finder = $this->fileFinder->createFinder();
            $files = $finder->files()->in($this->directory)->name($filePattern);

            $success = true;
            foreach ($files as $file) {
                $success = $success && $this->fileManager->remove($file->getPathname());
            }

            return $success;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Get all cache keys
     *
     * Scans the cache directory for cache files.
     *
     * @param string $pattern Optional pattern to filter keys
     * @return array List of cache keys
     */
    public function getKeys(string $pattern = '*'): array
    {
        try {
            $filePattern = str_replace(['*', '/', '\\'], ['*', '_', '_'], $pattern) . '.cache';

            $finder = $this->fileFinder->createFinder();
            $files = $finder->files()->in($this->directory)->name($filePattern);

            $keys = [];
            foreach ($files as $file) {
                $basename = $file->getBasename('.cache');
                // Convert file name back to cache key
                $key = str_replace('_', '/', $basename);
                $keys[] = $key;
            }

            return $keys;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Get cache statistics and information
     *
     * Returns file cache statistics.
     *
     * @return array Cache statistics
     */
    public function getStats(): array
    {
        try {
            $finder = $this->fileFinder->createFinder();
            $files = $finder->files()->in($this->directory)->name('*.cache');
            $totalFiles = 0;
            $totalSize = 0;
            $expiredCount = 0;

            foreach ($files as $file) {
                $totalFiles++;
                $totalSize += $file->getSize();

                // Check if file is expired
                $key = $file->getBasename('.cache');
                $key = str_replace('_', '/', $key);

                if ($this->ttl($key) === -2) {
                    $expiredCount++;
                }
            }

            return [
                'driver' => 'file',
                'directory' => $this->directory,
                'permissions' => substr(sprintf('%o', fileperms($this->directory)), -4),
                'total_keys' => $totalFiles,
                'expired_keys' => $expiredCount,
                'active_keys' => $totalFiles - $expiredCount,
                'total_size' => $totalSize,
                'total_size_human' => $this->formatBytes($totalSize),
                'disk_free_space' => disk_free_space($this->directory),
                'disk_free_space_human' => $this->formatBytes((int)(disk_free_space($this->directory) ?: 0)),
            ];
        } catch (\Exception $e) {
            return [
                'driver' => 'file',
                'error' => 'Failed to get stats: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get all cache keys
     *
     * Scans the cache directory for all cache files.
     *
     * @return array List of all cache keys
     */
    public function getAllKeys(): array
    {
        return $this->getKeys('*');
    }

    /**
     * Check if a cache key exists (PSR-16)
     *
     * @param string $key Cache key
     * @return bool True if key exists
     * @throws PSRInvalidArgumentException If key is invalid
     */
    public function has(string $key): bool
    {
        $this->validateKey($key);
        $filePath = $this->getFilePath($key);
        $metaPath = $this->getFilePath($key, self::META_EXT);

        if (!$this->fileManager->exists($filePath) || !$this->fileManager->exists($metaPath)) {
            return false;
        }

        // Check if expired
        $meta = $this->loadFromFile($metaPath);
        if ($meta === null || (isset($meta['expires']) && $meta['expires'] > 0 && $meta['expires'] < time())) {
            // Clean up expired files
            $this->fileManager->remove($filePath);
            $this->fileManager->remove($metaPath);
            return false;
        }

        return true;
    }

    /**
     * Get multiple cached values (PSR-16)
     *
     * @param iterable $keys Cache keys
     * @param mixed $default Default value for missing keys
     * @return iterable Values in same order as keys
     * @throws PSRInvalidArgumentException If any key is invalid
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $keyArray = is_array($keys) ? $keys : iterator_to_array($keys);
        $result = [];

        foreach ($keyArray as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * Store multiple values in cache (PSR-16)
     *
     * @param iterable $values Key-value pairs
     * @param null|int|\DateInterval $ttl Time to live
     * @return bool True if all values stored successfully
     * @throws PSRInvalidArgumentException If any key is invalid
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $valueArray = is_array($values) ? $values : iterator_to_array($values);
        $success = true;

        foreach ($valueArray as $key => $value) {
            $success = $success && $this->set($key, $value, $ttl);
        }

        return $success;
    }

    /**
     * Delete multiple cache keys (PSR-16)
     *
     * @param iterable $keys Cache keys
     * @return bool True if all keys deleted successfully
     * @throws PSRInvalidArgumentException If any key is invalid
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $keyArray = is_array($keys) ? $keys : iterator_to_array($keys);
        $success = true;

        foreach ($keyArray as $key) {
            $success = $success && $this->delete($key);
        }

        return $success;
    }

    /**
     * Get count of keys matching pattern
     *
     * @param string $pattern Pattern to match (supports wildcards *)
     * @return int Number of matching keys
     */
    public function getKeyCount(string $pattern = '*'): int
    {
        return count($this->getKeys($pattern));
    }

    /**
     * Get cache driver capabilities
     *
     * @return array Driver capabilities and features
     */
    public function getCapabilities(): array
    {
        return [
            'driver' => 'file',
            'features' => [
                'persistent' => true,
                'distributed' => false,      // File cache is local only
                'atomic_operations' => true, // Via file locking
                'pattern_deletion' => true,
                'sorted_sets' => true,
                'counters' => true,
                'expiration' => true,
                'bulk_operations' => true,
                'tags' => false,             // Not implemented yet
                'key_enumeration' => true,
            ],
            'data_types' => ['string', 'integer', 'float', 'boolean', 'array', 'object'],
            'max_key_length' => 255,        // Filesystem limit
            'max_value_size' => null,       // Limited by disk space
            'directory' => $this->directory,
            'permissions' => substr(sprintf('%o', fileperms($this->directory)), -4),
        ];
    }

    /**
     * Add tags to a cache key for grouped invalidation
     *
     * @param string $key Cache key
     * @param array $tags Array of tags to associate with the key
     * @return bool True if tags added successfully
     */
    public function addTags(string $key, array $tags): bool
    {
        // TODO: Implement tagging system using additional metadata files
        // For now, return false to indicate not implemented
        return false;
    }

    /**
     * Invalidate all cache entries with specified tags
     *
     * @param array $tags Array of tags to invalidate
     * @return bool True if invalidation successful
     */
    public function invalidateTags(array $tags): bool
    {
        // TODO: Implement tag-based invalidation
        // For now, return false to indicate not implemented
        return false;
    }

    /**
     * Remember pattern - get from cache or execute callback and store result
     *
     * @param string $key Cache key
     * @param callable $callback Function to execute if cache miss
     * @param int|null $ttl Time to live in seconds (null = default)
     * @return mixed Cached or computed value
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl ?? 3600);

        return $value;
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $size Size in bytes
     * @return string Formatted size
     */
    private function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $size > 0 ? floor(log($size, 1024)) : 0;

        if ($power >= count($units)) {
            $power = count($units) - 1;
        }

        return round($size / (1024 ** $power), 2) . ' ' . $units[$power];
    }

    /**
     * Validate cache key according to PSR-16 requirements
     *
     * @param string $key Cache key to validate
     * @throws PSRInvalidArgumentException If key is invalid
     */
    private function validateKey(string $key): void
    {
        if ($key === '') {
            throw CacheException::emptyKey();
        }

        if (strpbrk($key, '{}()/\\@:') !== false) {
            throw CacheException::invalidCharacters($key);
        }

        // Filesystem-safe length limit
        if (strlen($key) > 255) {
            throw CacheException::invalidKey($key . ' (exceeds 255 character limit)');
        }
    }

    /**
     * Normalize TTL value to seconds
     *
     * @param null|int|\DateInterval $ttl TTL value
     * @return int|null TTL in seconds or null for no expiration
     */
    private function normalizeTtl(null|int|\DateInterval $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            $future = $now->add($ttl);
            return $future->getTimestamp() - $now->getTimestamp();
        }

        return max(1, (int) $ttl);
    }
}
