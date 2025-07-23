<?php

declare(strict_types=1);

namespace Glueful\Queue\Process;

use Glueful\Queue\QueueManager;
use Glueful\Queue\WorkerOptions;
use Psr\Log\LoggerInterface;

/**
 * Auto-scaling Service for Queue Workers
 *
 * Automatically scales workers based on queue load, resource usage,
 * and predefined rules. Supports both scale-up and scale-down operations
 * with configurable thresholds and cooldown periods.
 */
class AutoScaler
{
    private ProcessManager $processManager;
    private QueueManager $queueManager;
    private LoggerInterface $logger;
    private array $config;
    private array $lastScaleTime = [];
    private array $scaleHistory = [];

    public function __construct(
        ProcessManager $processManager,
        QueueManager $queueManager,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->processManager = $processManager;
        $this->queueManager = $queueManager;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Perform auto-scaling check for all configured queues
     */
    public function scale(): array
    {
        if (!$this->config['enabled']) {
            return ['message' => 'Auto-scaling is disabled'];
        }

        $scalingActions = [];
        $queueConfigs = $this->config['queues'] ?? [];

        foreach ($queueConfigs as $queueName => $queueConfig) {
            if (!($queueConfig['auto_scale'] ?? false)) {
                continue;
            }

            $action = $this->scaleQueue($queueName, $queueConfig);
            if ($action) {
                $scalingActions[] = $action;
            }
        }

        return $scalingActions;
    }

    /**
     * Scale a specific queue based on load and configuration
     */
    public function scaleQueue(string $queueName, array $queueConfig): ?array
    {
        // Check cooldown period
        if ($this->isInCooldownPeriod($queueName)) {
            return null;
        }

        $metrics = $this->gatherQueueMetrics($queueName);
        $currentWorkers = $this->processManager->getWorkerCount($queueName);

        $decision = $this->makeScalingDecision($queueName, $metrics, $currentWorkers, $queueConfig);

        if ($decision['action'] === 'none') {
            return null;
        }

        $targetWorkers = $decision['target_workers'];

        try {
            $workerOptions = $this->createWorkerOptions($queueConfig);
            $this->processManager->scale($targetWorkers, $queueName, $workerOptions);

            $this->recordScalingAction($queueName, $currentWorkers, $targetWorkers, $decision['reason']);

            return [
                'queue' => $queueName,
                'action' => $decision['action'],
                'from' => $currentWorkers,
                'to' => $targetWorkers,
                'reason' => $decision['reason'],
                'metrics' => $metrics,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to scale queue', [
                'queue' => $queueName,
                'target_workers' => $targetWorkers,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Make scaling decision based on metrics and configuration
     */
    private function makeScalingDecision(
        string $queueName,
        array $metrics,
        int $currentWorkers,
        array $queueConfig
    ): array {
        $maxWorkers = $queueConfig['max_workers'] ?? $this->config['limits']['max_workers_per_queue'];
        $minWorkers = $queueConfig['min_workers'] ?? 1;

        // Check for scale-up conditions
        if ($this->shouldScaleUp($metrics, $currentWorkers, $queueConfig)) {
            $scaleUpStep = $this->config['auto_scale']['scale_up_step'] ?? 2;
            $targetWorkers = min($currentWorkers + $scaleUpStep, $maxWorkers);

            if ($targetWorkers > $currentWorkers) {
                return [
                    'action' => 'scale_up',
                    'target_workers' => $targetWorkers,
                    'reason' => $this->buildScaleUpReason($metrics, $queueConfig),
                ];
            }
        }

        // Check for scale-down conditions
        if ($this->shouldScaleDown($metrics, $currentWorkers, $queueConfig)) {
            $scaleDownStep = $this->config['auto_scale']['scale_down_step'] ?? 1;
            $targetWorkers = max($currentWorkers - $scaleDownStep, $minWorkers);

            if ($targetWorkers < $currentWorkers) {
                return [
                    'action' => 'scale_down',
                    'target_workers' => $targetWorkers,
                    'reason' => $this->buildScaleDownReason($metrics, $queueConfig),
                ];
            }
        }

        return ['action' => 'none', 'target_workers' => $currentWorkers, 'reason' => 'No scaling needed'];
    }

    /**
     * Check if queue should scale up
     */
    private function shouldScaleUp(array $metrics, int $currentWorkers, array $queueConfig): bool
    {
        $scaleUpThreshold = $queueConfig['scale_up_threshold'] ?? $this->config['auto_scale']['scale_up_threshold'];
        $maxWorkers = $queueConfig['max_workers'] ?? $this->config['limits']['max_workers_per_queue'];

        // Can't scale up if already at max
        if ($currentWorkers >= $maxWorkers) {
            return false;
        }

        // Scale up based on queue size
        if ($metrics['queue_size'] > $scaleUpThreshold) {
            return true;
        }

        // Scale up based on processing rate vs incoming rate
        if ($metrics['incoming_rate'] > $metrics['processing_rate'] * 1.5) {
            return true;
        }

        // Scale up based on worker utilization
        if ($metrics['avg_worker_utilization'] > 85) {
            return true;
        }

        // Scale up based on wait time
        if ($metrics['avg_wait_time'] > ($queueConfig['max_wait_time'] ?? 60)) {
            return true;
        }

        return false;
    }

    /**
     * Check if queue should scale down
     */
    private function shouldScaleDown(array $metrics, int $currentWorkers, array $queueConfig): bool
    {
        $scaleDownThreshold = $queueConfig['scale_down_threshold']
            ?? $this->config['auto_scale']['scale_down_threshold'];
        $minWorkers = $queueConfig['min_workers'] ?? 1;

        // Can't scale down if already at min
        if ($currentWorkers <= $minWorkers) {
            return false;
        }

        // Scale down based on queue size
        if ($metrics['queue_size'] < $scaleDownThreshold) {
            // Additional checks to prevent premature scale-down
            if ($metrics['avg_worker_utilization'] < 30 && $metrics['avg_wait_time'] < 10) {
                return true;
            }
        }

        // Scale down if processing capacity significantly exceeds demand
        if ($metrics['processing_rate'] > $metrics['incoming_rate'] * 2 && $metrics['queue_size'] < 5) {
            return true;
        }

        return false;
    }

    /**
     * Gather metrics for scaling decisions
     */
    private function gatherQueueMetrics(string $queueName): array
    {
        // Get queue size from QueueManager
        $queueSize = $this->queueManager->size($queueName);

        // Get worker metrics from ProcessManager
        $workerStatus = $this->processManager->getStatus();
        $queueWorkers = array_filter($workerStatus, fn($w) => $w['queue'] === $queueName);

        $totalJobs = array_sum(array_column($queueWorkers, 'jobs_processed'));
        $avgMemoryUsage = count($queueWorkers) > 0
            ? array_sum(array_column($queueWorkers, 'memory_usage')) / count($queueWorkers)
            : 0;
        $avgCpuUsage = count($queueWorkers) > 0
            ? array_sum(array_column($queueWorkers, 'cpu_usage')) / count($queueWorkers)
            : 0;

        // Calculate rates (this would ideally use historical data)
        $processingRate = $this->calculateProcessingRate($queueName, $queueWorkers);
        $incomingRate = $this->calculateIncomingRate($queueName);
        $avgWaitTime = $this->calculateAvgWaitTime($queueName);

        return [
            'queue_size' => $queueSize,
            'worker_count' => count($queueWorkers),
            'total_jobs_processed' => $totalJobs,
            'avg_memory_usage' => $avgMemoryUsage,
            'avg_cpu_usage' => $avgCpuUsage,
            'avg_worker_utilization' => $avgCpuUsage, // Simplified
            'processing_rate' => $processingRate,
            'incoming_rate' => $incomingRate,
            'avg_wait_time' => $avgWaitTime,
            'timestamp' => time(),
        ];
    }

    /**
     * Calculate processing rate (jobs per minute)
     */
    private function calculateProcessingRate(string $queueName, array $workers): float
    {
        // This would ideally use historical data over a time window
        // For now, we'll estimate based on current worker performance
        if (empty($workers)) {
            return 0.0;
        }

        $totalJobs = array_sum(array_column($workers, 'jobs_processed'));
        $avgRuntime = array_sum(array_column($workers, 'runtime')) / count($workers);

        if ($avgRuntime <= 0) {
            return 0.0;
        }

        // Jobs per minute estimate
        return ($totalJobs / ($avgRuntime / 60));
    }

    /**
     * Calculate incoming job rate (jobs per minute)
     */
    private function calculateIncomingRate(string $queueName): float
    {
        // This would require tracking job creation rate
        // For now, return a placeholder that could be enhanced with metrics storage
        return 10.0; // Placeholder
    }

    /**
     * Calculate average wait time for jobs
     */
    private function calculateAvgWaitTime(string $queueName): float
    {
        // This would require tracking job queue times
        // For now, estimate based on queue size and processing rate
        $queueSize = $this->queueManager->size($queueName);
        $processingRate = $this->calculateProcessingRate($queueName, []);

        if ($processingRate <= 0) {
            return $queueSize > 0 ? 60.0 : 0.0; // Default to 1 minute if no processing
        }

        return ($queueSize / $processingRate) * 60; // Convert to seconds
    }

    /**
     * Check if queue is in cooldown period
     */
    private function isInCooldownPeriod(string $queueName): bool
    {
        $cooldownPeriod = $this->config['auto_scale']['cooldown_period'] ?? 300;
        $lastScaleTime = $this->lastScaleTime[$queueName] ?? 0;

        return (time() - $lastScaleTime) < $cooldownPeriod;
    }

    /**
     * Record scaling action for history and cooldown tracking
     */
    private function recordScalingAction(string $queueName, int $fromWorkers, int $toWorkers, string $reason): void
    {
        $this->lastScaleTime[$queueName] = time();

        $this->scaleHistory[] = [
            'queue' => $queueName,
            'timestamp' => time(),
            'from_workers' => $fromWorkers,
            'to_workers' => $toWorkers,
            'reason' => $reason,
        ];

        // Keep only last 100 entries
        if (count($this->scaleHistory) > 100) {
            $this->scaleHistory = array_slice($this->scaleHistory, -100);
        }

        $this->logger->info('Auto-scaled queue workers', [
            'queue' => $queueName,
            'from_workers' => $fromWorkers,
            'to_workers' => $toWorkers,
            'reason' => $reason,
        ]);
    }

    /**
     * Create worker options from queue configuration
     */
    private function createWorkerOptions(array $queueConfig): WorkerOptions
    {
        return new WorkerOptions(
            memory: $queueConfig['memory_limit'] ?? 128,
            timeout: $queueConfig['timeout'] ?? 60,
            maxJobs: $queueConfig['max_jobs'] ?? 1000,
            maxAttempts: $queueConfig['max_attempts'] ?? 3,
        );
    }

    /**
     * Build scale-up reason message
     */
    private function buildScaleUpReason(array $metrics, array $queueConfig): string
    {
        $reasons = [];

        $scaleUpThreshold = $queueConfig['scale_up_threshold'] ?? $this->config['auto_scale']['scale_up_threshold'];
        if ($metrics['queue_size'] > $scaleUpThreshold) {
            $reasons[] = "Queue size ({$metrics['queue_size']}) > threshold ({$scaleUpThreshold})";
        }

        if ($metrics['incoming_rate'] > $metrics['processing_rate'] * 1.5) {
            $reasons[] = "Incoming rate exceeds processing capacity";
        }

        if ($metrics['avg_worker_utilization'] > 85) {
            $reasons[] = "High worker utilization ({$metrics['avg_worker_utilization']}%)";
        }

        return implode(', ', $reasons) ?: 'Load-based scaling';
    }

    /**
     * Build scale-down reason message
     */
    private function buildScaleDownReason(array $metrics, array $queueConfig): string
    {
        $reasons = [];

        $scaleDownThreshold = $queueConfig['scale_down_threshold']
            ?? $this->config['auto_scale']['scale_down_threshold'];
        if ($metrics['queue_size'] < $scaleDownThreshold) {
            $reasons[] = "Queue size ({$metrics['queue_size']}) < threshold ({$scaleDownThreshold})";
        }

        if ($metrics['avg_worker_utilization'] < 30) {
            $reasons[] = "Low worker utilization ({$metrics['avg_worker_utilization']}%)";
        }

        return implode(', ', $reasons) ?: 'Low load scaling';
    }

    /**
     * Get scaling history
     */
    public function getScalingHistory(?string $queueName = null): array
    {
        if ($queueName === null) {
            return $this->scaleHistory;
        }

        return array_filter($this->scaleHistory, fn($entry) => $entry['queue'] === $queueName);
    }

    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'enabled' => false,
            'limits' => [
                'max_workers_per_queue' => 10,
            ],
            'auto_scale' => [
                'scale_up_threshold' => 100,
                'scale_down_threshold' => 10,
                'scale_up_step' => 2,
                'scale_down_step' => 1,
                'cooldown_period' => 300,
            ],
            'queues' => [],
        ];
    }

    /**
     * Force scaling for a queue (bypasses cooldown)
     */
    public function forceScale(string $queueName, int $targetWorkers, string $reason = 'Manual scaling'): bool
    {
        try {
            $currentWorkers = $this->processManager->getWorkerCount($queueName);
            $queueConfig = $this->config['queues'][$queueName] ?? [];
            $workerOptions = $this->createWorkerOptions($queueConfig);

            $this->processManager->scale($targetWorkers, $queueName, $workerOptions);
            $this->recordScalingAction($queueName, $currentWorkers, $targetWorkers, $reason);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to force scale queue', [
                'queue' => $queueName,
                'target_workers' => $targetWorkers,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
