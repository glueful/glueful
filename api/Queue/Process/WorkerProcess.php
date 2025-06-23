<?php

declare(strict_types=1);

namespace Glueful\Queue\Process;

use Glueful\Queue\WorkerOptions;
use Symfony\Component\Process\Process;
use Psr\Log\LoggerInterface;

class WorkerProcess
{
    private Process $process;
    private string $workerId;
    private string $queue;
    private WorkerOptions $options;
    private LoggerInterface $logger;
    private \DateTime $startedAt;
    private ?\DateTime $lastHeartbeat = null;
    private int $jobsProcessed = 0;
    private array $metrics = [
        'memory_usage' => 0,
        'cpu_usage' => 0.0,
        'peak_memory' => 0,
    ];

    public function __construct(
        Process $process,
        string $workerId,
        string $queue,
        WorkerOptions $options,
        LoggerInterface $logger
    ) {
        $this->process = $process;
        $this->workerId = $workerId;
        $this->queue = $queue;
        $this->options = $options;
        $this->logger = $logger;
        $this->startedAt = new \DateTime();
    }

    public function start(): void
    {
        $this->process->start(function ($type, $buffer) {
            $this->handleOutput($type, $buffer);
        });

        $this->logger->info('Worker process started', [
            'worker_id' => $this->workerId,
            'queue' => $this->queue,
            'pid' => $this->process->getPid(),
            'command' => $this->process->getCommandLine(),
        ]);

        $this->updateHeartbeat();
    }

    public function stop(int $timeout = 30): void
    {
        if (!$this->process->isRunning()) {
            return;
        }

        // Send SIGTERM for graceful shutdown
        $this->process->signal(SIGTERM);

        $deadline = time() + $timeout;
        while ($this->process->isRunning() && time() < $deadline) {
            usleep(100000); // 100ms
        }

        if ($this->process->isRunning()) {
            $this->logger->warning('Worker did not stop gracefully, forcing termination', [
                'worker_id' => $this->workerId,
            ]);
            $this->forceStop();
        }
    }

    public function forceStop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(0); // Immediate termination
            $this->logger->info('Worker forcefully stopped', [
                'worker_id' => $this->workerId,
            ]);
        }
    }

    public function restart(): void
    {
        $this->stop();
        $this->jobsProcessed = 0;
        $this->startedAt = new \DateTime();
        $this->start();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function isHealthy(): bool
    {
        if (!$this->isRunning()) {
            return false;
        }

        // Check if heartbeat is recent (within last 60 seconds)
        if ($this->lastHeartbeat === null) {
            return false;
        }

        $secondsSinceHeartbeat = time() - $this->lastHeartbeat->getTimestamp();
        return $secondsSinceHeartbeat < 60;
    }

    public function getOutput(): string
    {
        return $this->process->getOutput();
    }

    public function getErrorOutput(): string
    {
        return $this->process->getErrorOutput();
    }

    public function getExitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    public function getWorkerId(): string
    {
        return $this->workerId;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getOptions(): WorkerOptions
    {
        return $this->options;
    }

    public function getPid(): ?int
    {
        return $this->process->getPid();
    }

    public function getStartedAt(): \DateTime
    {
        return $this->startedAt;
    }

    public function getLastHeartbeat(): ?\DateTime
    {
        return $this->lastHeartbeat;
    }

    public function getJobsProcessed(): int
    {
        return $this->jobsProcessed;
    }

    public function getMemoryUsage(): int
    {
        return $this->metrics['memory_usage'];
    }

    public function getPeakMemoryUsage(): int
    {
        return $this->metrics['peak_memory'];
    }

    public function getCpuUsage(): float
    {
        return $this->metrics['cpu_usage'];
    }

    public function updateHeartbeat(): void
    {
        $this->lastHeartbeat = new \DateTime();
    }

    public function incrementJobsProcessed(): void
    {
        $this->jobsProcessed++;
    }

    public function updateMetrics(array $metrics): void
    {
        $this->metrics = array_merge($this->metrics, $metrics);

        // Update peak memory if current is higher
        if (isset($metrics['memory_usage']) && $metrics['memory_usage'] > $this->metrics['peak_memory']) {
            $this->metrics['peak_memory'] = $metrics['memory_usage'];
        }
    }

    private function handleOutput(string $type, string $buffer): void
    {
        // Parse worker output for metrics and heartbeats
        $lines = explode("\n", trim($buffer));

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            // Check for heartbeat messages
            if (strpos($line, '[HEARTBEAT]') !== false) {
                $this->updateHeartbeat();
                continue;
            }

            // Check for job completion messages
            if (strpos($line, '[JOB_COMPLETED]') !== false) {
                $this->incrementJobsProcessed();
            }

            // Check for metrics updates
            if (strpos($line, '[METRICS]') !== false) {
                $this->parseMetrics($line);
            }

            // Log output based on type
            if ($type === Process::ERR) {
                $this->logger->error('Worker error output', [
                    'worker_id' => $this->workerId,
                    'output' => $line,
                ]);
            } else {
                $this->logger->debug('Worker output', [
                    'worker_id' => $this->workerId,
                    'output' => $line,
                ]);
            }
        }
    }

    private function parseMetrics(string $line): void
    {
        // Parse metrics from output line
        // Expected format: [METRICS] memory=123456789 cpu=45.2
        if (preg_match('/\[METRICS\]\s+memory=(\d+)\s+cpu=([\d.]+)/', $line, $matches)) {
            $this->updateMetrics([
                'memory_usage' => (int) $matches[1],
                'cpu_usage' => (float) $matches[2],
            ]);
        }
    }

    public function getRuntime(): int
    {
        return time() - $this->startedAt->getTimestamp();
    }

    public function toArray(): array
    {
        return [
            'worker_id' => $this->workerId,
            'queue' => $this->queue,
            'pid' => $this->getPid(),
            'status' => $this->isRunning() ? 'running' : 'stopped',
            'healthy' => $this->isHealthy(),
            'started_at' => $this->startedAt->format('c'),
            'last_heartbeat' => $this->lastHeartbeat ? $this->lastHeartbeat->format('c') : null,
            'runtime' => $this->getRuntime(),
            'jobs_processed' => $this->jobsProcessed,
            'memory_usage' => $this->metrics['memory_usage'],
            'peak_memory' => $this->metrics['peak_memory'],
            'cpu_usage' => $this->metrics['cpu_usage'],
            'exit_code' => $this->getExitCode(),
        ];
    }
}
