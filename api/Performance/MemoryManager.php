<?php

namespace Glueful\Performance;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class MemoryManager
{
    private $memoryLimit;
    private $alertThreshold;
    private $criticalThreshold;
    private $logger;

    /**
     * Initialize the Memory Manager
     *
     * @param LoggerInterface|null $logger Optional logger instance, uses NullLogger if not provided
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit'));
        $this->alertThreshold = config('app.performance.memory.alert_threshold', 0.8);
        $this->criticalThreshold = config('app.performance.memory.critical_threshold', 0.9);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Monitor memory usage and take action if threshold exceeded
     *
     * @return array Memory usage information with keys: current, limit, percentage
     */
    public function monitor(): array
    {
        $usage = $this->getCurrentUsage();

        if ($usage['percentage'] > $this->criticalThreshold) {
            $this->handleCriticalMemoryUsage($usage);
        } elseif ($usage['percentage'] > $this->alertThreshold) {
            $this->handleHighMemoryUsage($usage);
        }

        return $usage;
    }

    /**
     * Force garbage collection
     *
     * @return bool True if garbage collection was performed, false otherwise
     */
    public function forceGarbageCollection(): bool
    {
        if (gc_enabled()) {
            gc_collect_cycles();
            return true;
        }

        return false;
    }

    /**
     * Parse PHP memory limit from ini setting
     *
     * @param string $memoryLimit The memory limit string from ini setting (e.g., '128M')
     * @return int Memory limit in bytes
     */
    private function parseMemoryLimit(string $memoryLimit): int
    {
        if ($memoryLimit === '-1') {
            // Unlimited memory
            return PHP_INT_MAX;
        }

        $value = (int) $memoryLimit;
        $lastChar = strtolower(substr($memoryLimit, -1));

        switch ($lastChar) {
            case 'g':
                $value *= 1024;
                // Fall through to 'M' case
            case 'm':
                $value *= 1024;
                // Fall through to 'K' case
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Get current memory usage statistics
     *
     * @return array Memory usage information with keys: current, limit, percentage
     */
    public function getCurrentUsage(): array
    {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);

        return [
            'current' => $current,
            'peak' => $peak,
            'limit' => $this->memoryLimit,
            'percentage' => $this->memoryLimit > 0 ? $current / $this->memoryLimit : 0,
            'peak_percentage' => $this->memoryLimit > 0 ? $peak / $this->memoryLimit : 0,
            'formatted' => [
                'current' => $this->formatBytes($current),
                'peak' => $this->formatBytes($peak),
                'limit' => $this->formatBytes($this->memoryLimit),
            ],
        ];
    }

    /**
     * Handle high memory usage (alert threshold exceeded)
     *
     * @param array $usage Memory usage information
     * @return void
     */
    private function handleHighMemoryUsage(array $usage): void
    {
        $this->logger->warning('High memory usage detected', [
            'current_memory' => $usage['formatted']['current'],
            'memory_limit' => $usage['formatted']['limit'],
            'percentage' => round($usage['percentage'] * 100, 2) . '%',
            'action' => 'Running optional garbage collection'
        ]);

        // Try to reduce memory usage
        $this->forceGarbageCollection();
    }

    /**
     * Handle critical memory usage (critical threshold exceeded)
     *
     * @param array $usage Memory usage information
     * @return void
     */
    private function handleCriticalMemoryUsage(array $usage): void
    {
        $this->logger->error('Critical memory usage detected', [
            'current_memory' => $usage['formatted']['current'],
            'memory_limit' => $usage['formatted']['limit'],
            'percentage' => round($usage['percentage'] * 100, 2) . '%',
            'action' => 'Emergency memory reclamation'
        ]);

        // Emergency actions to prevent crash
        $this->forceGarbageCollection();
        $this->clearInternalCaches();

        // Note: Event handling removed to avoid dependency on Laravel's event() helper
    }

    /**
     * Clear internal caches to free memory
     *
     * @return void
     */
    private function clearInternalCaches(): void
    {
        // We don't access the framework caches directly anymore
        // as it required Laravel's app() helper function

        // Clear opcache if available and appropriate
        if (function_exists('opcache_reset') && !in_array(php_sapi_name(), ['cli', 'phpdbg'])) {
            opcache_reset();
        }
    }

    /**
     * Format bytes to human-readable format
     *
     * @param int $bytes Number of bytes
     * @param int $precision Number of decimal places
     * @return string Formatted size string
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max($bytes, 0);
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Check if current memory usage is above alert threshold
     *
     * @return bool True if memory usage is above alert threshold
     */
    public function isMemoryHighUsage(): bool
    {
        $usage = $this->getCurrentUsage();
        return $usage['percentage'] > $this->alertThreshold;
    }

    /**
     * Check if current memory usage is above critical threshold
     *
     * @return bool True if memory usage is above critical threshold
     */
    public function isMemoryCritical(): bool
    {
        $usage = $this->getCurrentUsage();
        return $usage['percentage'] > $this->criticalThreshold;
    }

    /**
     * Get memory limit in bytes
     *
     * @return int Memory limit in bytes
     */
    public function getMemoryLimit(): int
    {
        return $this->memoryLimit;
    }

    /**
     * Get the formatted memory limit
     *
     * @return string Formatted memory limit
     */
    public function getFormattedMemoryLimit(): string
    {
        return $this->formatBytes($this->memoryLimit);
    }
}
