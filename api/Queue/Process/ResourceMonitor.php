<?php

declare(strict_types=1);

namespace Glueful\Queue\Process;

use Psr\Log\LoggerInterface;

/**
 * Resource Monitor for Queue Worker Scaling
 *
 * Monitors system resources (CPU, memory, disk) to make informed
 * scaling decisions and prevent resource exhaustion.
 */
class ResourceMonitor
{
    private LoggerInterface $logger;
    private array $config;
    private array $resourceHistory = [];
    private array $thresholds;

    public function __construct(LoggerInterface $logger, array $config = [])
    {
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->thresholds = $this->config['thresholds'];
    }

    /**
     * Get current system resource usage
     */
    public function getCurrentResources(): array
    {
        return [
            'memory' => $this->getMemoryUsage(),
            'cpu' => $this->getCpuUsage(),
            'disk' => $this->getDiskUsage(),
            'load_average' => $this->getLoadAverage(),
            'processes' => $this->getProcessCount(),
            'timestamp' => time(),
        ];
    }

    /**
     * Check if scaling up is safe based on resource limits
     */
    public function canScaleUp(int $additionalWorkers = 1): array
    {
        $resources = $this->getCurrentResources();
        $projectedUsage = $this->projectResourceUsage($resources, $additionalWorkers);

        $canScale = true;
        $reasons = [];

        // Check memory limits
        if ($projectedUsage['memory']['percentage'] > $this->thresholds['memory']['scale_limit']) {
            $canScale = false;
            $reasons[] = sprintf(
                'Projected memory usage (%.1f%%) exceeds scale limit (%.1f%%)',
                $projectedUsage['memory']['percentage'],
                $this->thresholds['memory']['scale_limit']
            );
        }

        // Check CPU limits
        if ($projectedUsage['cpu']['percentage'] > $this->thresholds['cpu']['scale_limit']) {
            $canScale = false;
            $reasons[] = sprintf(
                'Projected CPU usage (%.1f%%) exceeds scale limit (%.1f%%)',
                $projectedUsage['cpu']['percentage'],
                $this->thresholds['cpu']['scale_limit']
            );
        }

        // Check disk limits
        if ($projectedUsage['disk']['percentage'] > $this->thresholds['disk']['scale_limit']) {
            $canScale = false;
            $reasons[] = sprintf(
                'Projected disk usage (%.1f%%) exceeds scale limit (%.1f%%)',
                $projectedUsage['disk']['percentage'],
                $this->thresholds['disk']['scale_limit']
            );
        }

        // Check load average
        if ($projectedUsage['load_average'] > $this->thresholds['load']['scale_limit']) {
            $canScale = false;
            $reasons[] = sprintf(
                'Projected load average (%.2f) exceeds scale limit (%.2f)',
                $projectedUsage['load_average'],
                $this->thresholds['load']['scale_limit']
            );
        }

        return [
            'can_scale' => $canScale,
            'reasons' => $reasons,
            'current_resources' => $resources,
            'projected_resources' => $projectedUsage,
            'additional_workers' => $additionalWorkers,
        ];
    }

    /**
     * Check if scaling down is recommended due to resource constraints
     */
    public function shouldScaleDownForResources(): array
    {
        $resources = $this->getCurrentResources();
        $shouldScale = false;
        $reasons = [];

        // Check if memory usage is critical
        if ($resources['memory']['percentage'] > $this->thresholds['memory']['critical']) {
            $shouldScale = true;
            $reasons[] = sprintf(
                'Memory usage (%.1f%%) is critical (>%.1f%%)',
                $resources['memory']['percentage'],
                $this->thresholds['memory']['critical']
            );
        }

        // Check if CPU usage is too high
        if ($resources['cpu']['percentage'] > $this->thresholds['cpu']['critical']) {
            $shouldScale = true;
            $reasons[] = sprintf(
                'CPU usage (%.1f%%) is critical (>%.1f%%)',
                $resources['cpu']['percentage'],
                $this->thresholds['cpu']['critical']
            );
        }

        // Check if load average is too high
        if ($resources['load_average'] > $this->thresholds['load']['critical']) {
            $shouldScale = true;
            $reasons[] = sprintf(
                'Load average (%.2f) is critical (>%.2f)',
                $resources['load_average'],
                $this->thresholds['load']['critical']
            );
        }

        return [
            'should_scale_down' => $shouldScale,
            'reasons' => $reasons,
            'current_resources' => $resources,
            'urgency' => $this->calculateUrgency($resources),
        ];
    }

    /**
     * Get memory usage information
     */
    private function getMemoryUsage(): array
    {
        $meminfo = $this->parseMeminfo();

        $total = $meminfo['MemTotal'] ?? 0;
        $free = $meminfo['MemFree'] ?? 0;
        $available = $meminfo['MemAvailable'] ?? $free;
        $used = $total - $available;

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'available' => $available,
            'percentage' => $total > 0 ? ($used / $total) * 100 : 0,
            'cached' => $meminfo['Cached'] ?? 0,
            'buffers' => $meminfo['Buffers'] ?? 0,
        ];
    }

    /**
     * Parse /proc/meminfo file
     */
    private function parseMeminfo(): array
    {
        $meminfo = [];

        if (file_exists('/proc/meminfo')) {
            $content = file_get_contents('/proc/meminfo');
            $lines = explode("\n", $content);

            foreach ($lines as $line) {
                if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $matches)) {
                    $meminfo[$matches[1]] = (int) $matches[2] * 1024; // Convert to bytes
                }
            }
        } else {
            // Fallback for systems without /proc/meminfo
            $meminfo = $this->getMemoryUsageFallback();
        }

        return $meminfo;
    }

    /**
     * Fallback memory usage detection
     */
    private function getMemoryUsageFallback(): array
    {
        $used = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);

        // Estimate total memory (this is very rough)
        $total = $peak * 10; // Assume peak is about 10% of total

        return [
            'MemTotal' => $total,
            'MemFree' => $total - $used,
            'MemAvailable' => $total - $used,
            'Cached' => 0,
            'Buffers' => 0,
        ];
    }

    /**
     * Get CPU usage information
     */
    private function getCpuUsage(): array
    {
        $cpuUsage = $this->calculateCpuUsage();

        return [
            'percentage' => $cpuUsage,
            'cores' => $this->getCpuCoreCount(),
            'model' => $this->getCpuModel(),
        ];
    }

    /**
     * Calculate CPU usage percentage
     */
    private function calculateCpuUsage(): float
    {
        if (file_exists('/proc/stat')) {
            $stat1 = $this->parseCpuStat();
            usleep(100000); // Wait 100ms
            $stat2 = $this->parseCpuStat();

            $idle1 = $stat1['idle'] + $stat1['iowait'];
            $idle2 = $stat2['idle'] + $stat2['iowait'];

            $total1 = array_sum($stat1);
            $total2 = array_sum($stat2);

            $totalDiff = $total2 - $total1;
            $idleDiff = $idle2 - $idle1;

            if ($totalDiff > 0) {
                return (($totalDiff - $idleDiff) / $totalDiff) * 100;
            }
        }

        // Fallback: estimate based on load average
        $loadAvg = $this->getLoadAverage();
        $cores = $this->getCpuCoreCount();

        return min(($loadAvg / $cores) * 100, 100);
    }

    /**
     * Parse CPU statistics from /proc/stat
     */
    private function parseCpuStat(): array
    {
        $stat = file_get_contents('/proc/stat');
        $lines = explode("\n", $stat);
        $cpuLine = $lines[0];

        $values = preg_split('/\s+/', $cpuLine);

        return [
            'user' => (int) ($values[1] ?? 0),
            'nice' => (int) ($values[2] ?? 0),
            'system' => (int) ($values[3] ?? 0),
            'idle' => (int) ($values[4] ?? 0),
            'iowait' => (int) ($values[5] ?? 0),
            'irq' => (int) ($values[6] ?? 0),
            'softirq' => (int) ($values[7] ?? 0),
            'steal' => (int) ($values[8] ?? 0),
        ];
    }

    /**
     * Get number of CPU cores
     */
    private function getCpuCoreCount(): int
    {
        if (file_exists('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            return substr_count($cpuinfo, 'processor');
        }

        return 1; // Fallback
    }

    /**
     * Get CPU model information
     */
    private function getCpuModel(): string
    {
        if (file_exists('/proc/cpuinfo')) {
            $cpuinfo = file_get_contents('/proc/cpuinfo');
            if (preg_match('/model name\s*:\s*(.+)/', $cpuinfo, $matches)) {
                return trim($matches[1]);
            }
        }

        return 'Unknown';
    }

    /**
     * Get disk usage information
     */
    private function getDiskUsage(): array
    {
        $path = $this->config['disk_monitor_path'] ?? '/';
        $total = disk_total_space($path);
        $free = disk_free_space($path);
        $used = $total - $free;

        return [
            'total' => $total,
            'used' => $used,
            'free' => $free,
            'percentage' => $total > 0 ? ($used / $total) * 100 : 0,
            'path' => $path,
        ];
    }

    /**
     * Get system load average
     */
    private function getLoadAverage(): float
    {
        if (function_exists('sys_getloadavg')) {
            $loads = sys_getloadavg();
            return $loads[0]; // 1-minute load average
        }

        if (file_exists('/proc/loadavg')) {
            $loadavg = file_get_contents('/proc/loadavg');
            $parts = explode(' ', $loadavg);
            return (float) ($parts[0] ?? 0);
        }

        return 0.0;
    }

    /**
     * Get process count
     */
    private function getProcessCount(): int
    {
        if (file_exists('/proc')) {
            $processes = glob('/proc/[0-9]*');
            return count($processes);
        }

        return 0;
    }

    /**
     * Project resource usage after adding workers
     */
    private function projectResourceUsage(array $currentResources, int $additionalWorkers): array
    {
        $workerMemoryMb = $this->config['worker_memory_mb'] ?? 128;
        $workerCpuPercent = $this->config['worker_cpu_percent'] ?? 10;

        $additionalMemoryBytes = $additionalWorkers * $workerMemoryMb * 1024 * 1024;
        $additionalCpuPercent = $additionalWorkers * $workerCpuPercent;

        $projectedMemory = $currentResources['memory'];
        $projectedMemory['used'] += $additionalMemoryBytes;
        $projectedMemory['available'] -= $additionalMemoryBytes;
        $projectedMemory['percentage'] = $projectedMemory['total'] > 0
            ? ($projectedMemory['used'] / $projectedMemory['total']) * 100
            : 0;

        $projectedCpu = $currentResources['cpu'];
        $projectedCpu['percentage'] = min($projectedCpu['percentage'] + $additionalCpuPercent, 100);

        return [
            'memory' => $projectedMemory,
            'cpu' => $projectedCpu,
            'disk' => $currentResources['disk'], // Disk usage won't change significantly
            'load_average' => $currentResources['load_average'] + ($additionalWorkers * 0.5),
            'processes' => $currentResources['processes'] + $additionalWorkers,
        ];
    }

    /**
     * Calculate urgency level for resource constraints
     */
    private function calculateUrgency(array $resources): string
    {
        $urgencyScore = 0;

        // Memory urgency
        if ($resources['memory']['percentage'] > $this->thresholds['memory']['critical']) {
            $urgencyScore += 3;
        } elseif ($resources['memory']['percentage'] > $this->thresholds['memory']['warning']) {
            $urgencyScore += 2;
        }

        // CPU urgency
        if ($resources['cpu']['percentage'] > $this->thresholds['cpu']['critical']) {
            $urgencyScore += 3;
        } elseif ($resources['cpu']['percentage'] > $this->thresholds['cpu']['warning']) {
            $urgencyScore += 2;
        }

        // Load urgency
        if ($resources['load_average'] > $this->thresholds['load']['critical']) {
            $urgencyScore += 2;
        } elseif ($resources['load_average'] > $this->thresholds['load']['warning']) {
            $urgencyScore += 1;
        }

        return match (true) {
            $urgencyScore >= 6 => 'critical',
            $urgencyScore >= 4 => 'high',
            $urgencyScore >= 2 => 'medium',
            default => 'low'
        };
    }

    /**
     * Store resource usage in history
     */
    public function recordResourceUsage(): void
    {
        $resources = $this->getCurrentResources();
        $this->resourceHistory[] = $resources;

        // Keep only last 100 entries
        if (count($this->resourceHistory) > 100) {
            $this->resourceHistory = array_slice($this->resourceHistory, -100);
        }

        // Log if resources are high
        if (
            $resources['memory']['percentage'] > $this->thresholds['memory']['warning'] ||
            $resources['cpu']['percentage'] > $this->thresholds['cpu']['warning']
        ) {
            $this->logger->warning('High resource usage detected', $resources);
        }
    }

    /**
     * Get resource usage history
     */
    public function getResourceHistory(int $limit = 50): array
    {
        return array_slice($this->resourceHistory, -$limit);
    }

    /**
     * Get resource usage trends
     */
    public function getResourceTrends(): array
    {
        if (count($this->resourceHistory) < 2) {
            return ['insufficient_data' => true];
        }

        $recent = array_slice($this->resourceHistory, -10);
        $older = array_slice($this->resourceHistory, -20, 10);

        $recentAvg = $this->calculateAverageResources($recent);
        $olderAvg = $this->calculateAverageResources($older);

        return [
            'memory_trend' => $recentAvg['memory']['percentage'] - $olderAvg['memory']['percentage'],
            'cpu_trend' => $recentAvg['cpu']['percentage'] - $olderAvg['cpu']['percentage'],
            'load_trend' => $recentAvg['load_average'] - $olderAvg['load_average'],
            'trending_up' => (
                $recentAvg['memory']['percentage'] > $olderAvg['memory']['percentage'] ||
                $recentAvg['cpu']['percentage'] > $olderAvg['cpu']['percentage']
            ),
        ];
    }

    /**
     * Calculate average resources from history
     */
    private function calculateAverageResources(array $history): array
    {
        if (empty($history)) {
            return [
                'memory' => ['percentage' => 0],
                'cpu' => ['percentage' => 0],
                'load_average' => 0,
            ];
        }

        $count = count($history);
        $memorySum = array_sum(array_column(array_column($history, 'memory'), 'percentage'));
        $cpuSum = array_sum(array_column(array_column($history, 'cpu'), 'percentage'));
        $loadSum = array_sum(array_column($history, 'load_average'));

        return [
            'memory' => ['percentage' => $memorySum / $count],
            'cpu' => ['percentage' => $cpuSum / $count],
            'load_average' => $loadSum / $count,
        ];
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'worker_memory_mb' => 128,
            'worker_cpu_percent' => 10,
            'disk_monitor_path' => '/',
            'thresholds' => [
                'memory' => [
                    'warning' => 75,
                    'critical' => 90,
                    'scale_limit' => 85,
                ],
                'cpu' => [
                    'warning' => 70,
                    'critical' => 90,
                    'scale_limit' => 80,
                ],
                'disk' => [
                    'warning' => 80,
                    'critical' => 95,
                    'scale_limit' => 90,
                ],
                'load' => [
                    'warning' => 2.0,
                    'critical' => 4.0,
                    'scale_limit' => 3.0,
                ],
            ],
        ];
    }
}
