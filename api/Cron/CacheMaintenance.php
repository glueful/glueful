<?php

namespace Glueful\Cron;

use Glueful\Cache\CacheFactory;
use Glueful\Cache\CacheStore;

class CacheMaintenance
{
    private array $stats = [
        'cache_cleared' => false,
        'expired_keys_removed' => 0,
        'cache_size_before' => 0,
        'cache_size_after' => 0,
        'errors' => []
    ];

    private CacheStore $cache;

    public function __construct()
    {
        $this->cache = CacheFactory::create();
    }

    public function clearExpiredKeys(): void
    {
        try {
            $cacheDir = dirname(__DIR__, 2) . '/storage/cache';

            if (!is_dir($cacheDir)) {
                return;
            }

            $this->stats['cache_size_before'] = $this->calculateCacheSize($cacheDir);

            // Clean file-based cache
            $this->cleanFileCache($cacheDir);

            // Clean Redis/Memcached if configured
            $this->cleanDistributedCache();

            $this->stats['cache_size_after'] = $this->calculateCacheSize($cacheDir);
            $this->stats['cache_cleared'] = true;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clear expired cache keys: " . $e->getMessage();
        }
    }

    private function cleanFileCache(string $cacheDir): void
    {
        $files = glob($cacheDir . '/*');
        $now = time();

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            try {
                $content = file_get_contents($file);
                $data = unserialize($content);

                // Check if cache entry has expired
                if (is_array($data) && isset($data['expires_at'])) {
                    if ($data['expires_at'] < $now) {
                        unlink($file);
                        $this->stats['expired_keys_removed']++;
                    }
                } else {
                    // Remove malformed cache files
                    $fileAge = filemtime($file);
                    if ($now - $fileAge > 86400) { // 24 hours
                        unlink($file);
                        $this->stats['expired_keys_removed']++;
                    }
                }
            } catch (\Exception $e) {
                // Skip files that can't be processed
                continue;
            }
        }
    }

    private function cleanDistributedCache(): void
    {
        // For distributed cache (Redis/Memcached), we can clear specific patterns
        // This is driver-specific and would need implementation based on the driver type

        // For now, we'll skip distributed cache cleanup since it's handled by the cache system itself
        // and expired keys are automatically cleaned by Redis/Memcached
    }

    private function calculateCacheSize(string $cacheDir): int
    {
        $size = 0;
        $files = glob($cacheDir . '/*');

        foreach ($files as $file) {
            if (is_file($file)) {
                $size += filesize($file);
            }
        }

        return $size;
    }

    public function optimizeCache(): void
    {
        try {
            // Perform cache optimization tasks
            $this->defragmentCache();
            $this->updateCacheStatistics();
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to optimize cache: " . $e->getMessage();
        }
    }

    private function defragmentCache(): void
    {
        // For file-based cache, we can reorganize files
        // For Redis/Memcached, this might involve compaction
        $cacheDir = dirname(__DIR__, 2) . '/storage/cache';

        if (!is_dir($cacheDir)) {
            return;
        }

        // Create a temporary directory for reorganization
        $tempDir = $cacheDir . '_temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Move valid cache files to temp directory
        $files = glob($cacheDir . '/*');
        $now = time();

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            try {
                $content = file_get_contents($file);
                $data = unserialize($content);

                // Only keep valid, non-expired cache entries
                if (is_array($data) && isset($data['expires_at']) && $data['expires_at'] > $now) {
                    $filename = basename($file);
                    copy($file, $tempDir . '/' . $filename);
                }
            } catch (\Exception) {
                // Skip invalid files
                continue;
            }
        }

        // Remove old cache directory and rename temp
        $this->removeDirectory($cacheDir);
        rename($tempDir, $cacheDir);
    }

    private function updateCacheStatistics(): void
    {
        // Update cache statistics file
        $statsFile = dirname(__DIR__, 2) . '/storage/cache/stats.json';
        $stats = [
            'last_maintenance' => time(),
            'keys_removed' => $this->stats['expired_keys_removed'],
            'size_before' => $this->stats['cache_size_before'],
            'size_after' => $this->stats['cache_size_after']
        ];

        file_put_contents($statsFile, json_encode($stats, JSON_PRETTY_PRINT));
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    public function logResults(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $spaceSaved = $this->stats['cache_size_before'] - $this->stats['cache_size_after'];

        $message = sprintf(
            "[%s] Cache maintenance completed:\n" .
            "- Cache cleared: %s\n" .
            "- Expired keys removed: %d\n" .
            "- Space saved: %s\n" .
            "- Cache size before: %s\n" .
            "- Cache size after: %s\n",
            $timestamp,
            $this->stats['cache_cleared'] ? 'Yes' : 'No',
            $this->stats['expired_keys_removed'],
            $this->formatBytes($spaceSaved),
            $this->formatBytes($this->stats['cache_size_before']),
            $this->formatBytes($this->stats['cache_size_after'])
        );

        if (!empty($this->stats['errors'])) {
            $message .= "Errors:\n- " . implode("\n- ", $this->stats['errors']) . "\n";
        }

        $logFile = dirname(__DIR__, 2) . '/storage/logs/cache-maintenance.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logFile, $message . "\n", FILE_APPEND);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }

    public function handle(array $parameters = []): mixed
    {
        // Parameters are reserved for future configuration options
        unset($parameters);

        $this->clearExpiredKeys();
        $this->optimizeCache();
        $this->logResults();

        return $this->stats;
    }
}
