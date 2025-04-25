<?php

declare(strict_types=1);

namespace Glueful\Cache\Drivers;

use InvalidArgumentException;

/**
 * File Cache Driver
 * 
 * Provides file-based caching as a fallback when Redis/Memcached are unavailable.
 * Implements CacheDriverInterface with file system storage.
 */
class FileCacheDriver implements CacheDriverInterface
{
    /** @var string Base directory for cache files */
    private string $directory;
    
    /** @var string File extension for cache files */
    private const FILE_EXT = '.cache';
    
    /** @var string Extension for metadata files */
    private const META_EXT = '.meta';
    
    /** @var string Extension for sorted set files */
    private const ZSET_EXT = '.zset';

    /**
     * Constructor
     * 
     * @param string $directory Cache directory path
     * @throws InvalidArgumentException If directory is invalid
     */
    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/') . '/';
        
        if (!is_dir($this->directory)) {
            if (!mkdir($this->directory, 0755, true)) {
                throw new InvalidArgumentException("Cannot create cache directory: {$this->directory}");
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
     * Save data to file
     * 
     * @param string $path File path
     * @param mixed $data Data to save
     * @return bool Success
     */
    private function saveToFile(string $path, mixed $data): bool
    {
        return file_put_contents($path, serialize($data)) !== false;
    }
    
    /**
     * Load data from file
     * 
     * @param string $path File path
     * @return mixed Loaded data or null
     */
    private function loadFromFile(string $path): mixed
    {
        if (!file_exists($path)) {
            return null;
        }
        
        $data = file_get_contents($path);
        if ($data === false) {
            return null;
        }
        
        return unserialize($data);
    }

    /**
     * Get cached value
     * 
     * @param string $key Cache key
     * @return mixed Cached value or null if not found
     */
    public function get(string $key): mixed
    {
        $filePath = $this->getFilePath($key);
        $metaPath = $this->getFilePath($key, self::META_EXT);
        
        if (!file_exists($filePath) || !file_exists($metaPath)) {
            return null;
        }
        
        // Check if expired
        $meta = $this->loadFromFile($metaPath);
        if ($meta === null || (isset($meta['expires']) && $meta['expires'] < time())) {
            // Clean up expired files
            @unlink($filePath);
            @unlink($metaPath);
            return null;
        }
        
        return $this->loadFromFile($filePath);
    }

    /**
     * Store value in cache
     * 
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int $ttl Time to live in seconds
     * @return bool True if stored successfully
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $filePath = $this->getFilePath($key);
        $metaPath = $this->getFilePath($key, self::META_EXT);
        
        $meta = [
            'created' => time(),
            'expires' => ($ttl > 0) ? time() + $ttl : 0,
            'ttl' => $ttl
        ];
        
        if (!$this->saveToFile($metaPath, $meta)) {
            return false;
        }
        
        return $this->saveToFile($filePath, $value);
    }

    /**
     * Delete cached value
     * 
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    public function delete(string $key): bool
    {
        return $this->del($key);
    }

    /**
     * Increment numeric value
     * 
     * @param string $key Cache key
     * @return bool True if incremented successfully
     */
    public function increment(string $key): bool
    {
        $value = $this->get($key);
        
        if (!is_numeric($value)) {
            return false;
        }
        
        $metaPath = $this->getFilePath($key, self::META_EXT);
        $meta = $this->loadFromFile($metaPath);
        if ($meta === null) {
            return false;
        }
        
        return $this->set($key, $value + 1, $meta['ttl']);
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
        
        if (!file_exists($metaPath)) {
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
     * Clear all cached values
     * 
     * @return bool True if cache cleared successfully
     */
    public function flush(): bool
    {
        $files = glob($this->directory . '*');
        
        if ($files === false) {
            return false;
        }
        
        $success = true;
        foreach ($files as $file) {
            if (is_file($file)) {
                $success = $success && unlink($file);
            }
        }
        
        return $success;
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
        if (!file_exists($metaPath)) {
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
        
        if (!file_exists($zsetPath)) {
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
        
        if (!file_exists($zsetPath)) {
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
        
        if (!file_exists($zsetPath)) {
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
        
        if (!file_exists($metaPath)) {
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
        
        if (file_exists($filePath)) {
            $success = $success && unlink($filePath);
        }
        
        if (file_exists($metaPath)) {
            $success = $success && unlink($metaPath);
        }
        
        if (file_exists($zsetPath)) {
            $success = $success && unlink($zsetPath);
        }
        
        return $success;
    }
}