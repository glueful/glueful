<?php

declare(strict_types=1);

namespace Glueful\Cache\Nodes;

/**
 * File Node
 *
 * File-based implementation of a cache node.
 * Useful for development or as a fallback when no other caching is available.
 */
class FileNode extends CacheNode
{
    /** @var string Base path for cache files */
    private $path;

    /** @var bool Directory status */
    private $initialized = false;

    /**
     * Initialize File node
     *
     * @param string $id Node identifier
     * @param array $config Node configuration
     */
    public function __construct(string $id, array $config)
    {
        parent::__construct($id, $config);

        $this->path = rtrim($config['path'] ?? sys_get_temp_dir() . '/glueful_cache', '/') . '/';
        $this->initialize();
    }

    /**
     * Initialize cache directory
     *
     * @return bool True if directory is ready
     */
    private function initialize(): bool
    {
        if (!is_dir($this->path)) {
            $created = mkdir($this->path, 0755, true);
            if (!$created) {
                error_log("Failed to create cache directory for node {$this->id}: {$this->path}");
                return false;
            }
        }

        if (!is_writable($this->path)) {
            error_log("Cache directory is not writable for node {$this->id}: {$this->path}");
            return false;
        }

        $this->initialized = true;
        return true;
    }

    /**
     * Ensure directory is initialized
     *
     * @return bool True if initialized
     */
    private function ensureInitialized(): bool
    {
        if (!$this->initialized) {
            return $this->initialize();
        }

        return true;
    }

    /**
     * Get full path for a cache key
     *
     * @param string $key Cache key
     * @return string Full file path
     */
    private function getFilePath(string $key): string
    {
        $hash = md5($key);
        $subDir = substr($hash, 0, 2);

        $dir = $this->path . $subDir;

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir . '/' . $hash . '.cache';
    }

    /**
     * Get metadata file path for a cache key
     *
     * @param string $key Cache key
     * @return string Metadata file path
     */
    private function getMetaFilePath(string $key): string
    {
        return $this->getFilePath($key) . '.meta';
    }

    /**
     * Set cache value
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds
     * @return bool True if set successfully
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!$this->ensureInitialized()) {
            return false;
        }

        $filePath = $this->getFilePath($key);
        $metaPath = $this->getMetaFilePath($key);

        try {
            // Store value
            $success = file_put_contents($filePath, serialize($value)) !== false;

            // Store metadata
            if ($success) {
                $meta = [
                    'key' => $key,
                    'created' => time(),
                    'expires' => $ttl > 0 ? (time() + $ttl) : 0
                ];

                file_put_contents($metaPath, json_encode($meta));
            }

            return $success;
        } catch (\Exception $e) {
            error_log("File cache set error for node {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get cached value
     *
     * @param string $key Cache key
     * @return mixed Cached value or null if not found
     */
    public function get(string $key)
    {
        if (!$this->ensureInitialized()) {
            return null;
        }

        $filePath = $this->getFilePath($key);
        $metaPath = $this->getMetaFilePath($key);

        // Check if files exist
        if (!file_exists($filePath) || !file_exists($metaPath)) {
            return null;
        }

        try {
            // Check expiration
            $meta = json_decode(file_get_contents($metaPath), true);

            if ($meta && isset($meta['expires']) && $meta['expires'] > 0 && $meta['expires'] < time()) {
                // Expired, delete files
                unlink($filePath);
                unlink($metaPath);
                return null;
            }

            // Return value
            return unserialize(file_get_contents($filePath));
        } catch (\Exception $e) {
            error_log("File cache get error for node {$this->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete cached value
     *
     * @param string $key Cache key
     * @return bool True if deleted successfully
     */
    public function delete(string $key): bool
    {
        if (!$this->ensureInitialized()) {
            return false;
        }

        $filePath = $this->getFilePath($key);
        $metaPath = $this->getMetaFilePath($key);

        $fileSuccess = true;
        $metaSuccess = true;

        if (file_exists($filePath)) {
            $fileSuccess = unlink($filePath);
        }

        if (file_exists($metaPath)) {
            $metaSuccess = unlink($metaPath);
        }

        $success = $fileSuccess && $metaSuccess;

        return $success;
    }

    /**
     * Clear all cached values
     *
     * @return bool True if cleared successfully
     */
    public function clear(): bool
    {
        if (!$this->ensureInitialized()) {
            return false;
        }

        try {
            $this->clearDirectory($this->path);
            return true;
        } catch (\Exception $e) {
            error_log("File cache clear error for node {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Recursively clear a directory
     *
     * @param string $dir Directory path
     * @return void
     */
    private function clearDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $this->clearDirectory($path);
                rmdir($path);
            } else {
                unlink($path);
            }
        }
    }

    /**
     * Check if key exists
     *
     * @param string $key Cache key
     * @return bool True if key exists
     */
    public function exists(string $key): bool
    {
        if (!$this->ensureInitialized()) {
            return false;
        }

        $filePath = $this->getFilePath($key);
        $metaPath = $this->getMetaFilePath($key);

        if (!file_exists($filePath) || !file_exists($metaPath)) {
            return false;
        }

        try {
            // Check expiration
            $meta = json_decode(file_get_contents($metaPath), true);

            if ($meta && isset($meta['expires']) && $meta['expires'] > 0 && $meta['expires'] < time()) {
                // Expired
                return false;
            }

            return true;
        } catch (\Exception $e) {
            error_log("File cache exists error for node {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get node status
     *
     * @return array Status information
     */
    public function getStatus(): array
    {
        $status = [
            'id' => $this->id,
            'driver' => 'file',
            'initialized' => $this->initialized,
            'path' => $this->path,
        ];

        if ($this->initialized) {
            try {
                $status['space_free'] = disk_free_space($this->path);
                $status['space_total'] = disk_total_space($this->path);

                // Count cache files
                $items = 0;
                $size = 0;
                $this->getDirInfo($this->path, $items, $size);

                $status['items'] = $items;
                $status['size'] = $size;
            } catch (\Exception $e) {
                $status['error'] = $e->getMessage();
            }
        }

        return $status;
    }

    /**
     * Get directory information
     *
     * @param string $dir Directory path
     * @param int &$items Number of items
     * @param int &$size Total size
     * @return void
     */
    private function getDirInfo(string $dir, int &$items, int &$size): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->getDirInfo($path, $items, $size);
            } elseif (substr($file, -6) === '.cache') {
                $items++;
                $size += filesize($path);
            }
        }
    }

    /**
     * Add a key to a tag set
     *
     * @param string $tag Tag name
     * @param string $key Key to add
     * @param int $score Score for sorted set (typically timestamp)
     * @return bool True if added successfully
     */
    public function addTaggedKey(string $tag, string $key, int $score): bool
    {
        if (!$this->ensureInitialized()) {
            return false;
        }

        $tagPath = $this->getFilePath($tag);

        try {
            // Read existing tag set
            $tagSet = [];

            if (file_exists($tagPath)) {
                $tagSet = unserialize(file_get_contents($tagPath)) ?: [];
            }

            // Add the key with score
            $tagSet[$key] = $score;

            // Write updated tag set
            return file_put_contents($tagPath, serialize($tagSet)) !== false;
        } catch (\Exception $e) {
            error_log("File cache addTaggedKey error for node {$this->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get keys from a tag set
     *
     * @param string $tag Tag name
     * @return array Keys in the tag set
     */
    public function getTaggedKeys(string $tag): array
    {
        if (!$this->ensureInitialized()) {
            return [];
        }

        $tagPath = $this->getFilePath($tag);

        if (!file_exists($tagPath)) {
            return [];
        }

        try {
            $tagSet = unserialize(file_get_contents($tagPath)) ?: [];
            return array_keys($tagSet);
        } catch (\Exception $e) {
            error_log("File cache getTaggedKeys error for node {$this->id}: " . $e->getMessage());
            return [];
        }
    }
}
