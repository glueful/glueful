<?php

declare(strict_types=1);

namespace Glueful\Queue\Process;

use Glueful\Queue\WorkerOptions;
use Glueful\Queue\Monitoring\WorkerMonitor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ProcessManager
{
    private array $workers = [];
    private ProcessFactory $factory;
    private WorkerMonitor $monitor;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        ProcessFactory $factory,
        WorkerMonitor $monitor,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->factory = $factory;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->config = array_merge([
            'max_workers' => 10,
            'restart_delay' => 5,
            'health_check_interval' => 30,
        ], $config);
    }

    public function spawn(string $queue, WorkerOptions $options): WorkerProcess
    {
        if (count($this->workers) >= $this->config['max_workers']) {
            throw new \RuntimeException(
                "Maximum number of workers ({$this->config['max_workers']}) reached"
            );
        }

        $worker = $this->factory->createWorker($queue, $options);
        $worker->start();

        $this->workers[$worker->getWorkerId()] = $worker;
        $this->logger->info('Spawned new worker', [
            'worker_id' => $worker->getWorkerId(),
            'queue' => $queue,
            'pid' => $worker->getPid(),
        ]);

        return $worker;
    }

    public function scale(int $count, string $queue = 'default', ?WorkerOptions $options = null): void
    {
        $options = $options ?? new WorkerOptions();
        $currentCount = $this->getWorkerCount($queue);

        if ($count > $currentCount) {
            // Scale up
            $toSpawn = $count - $currentCount;
            for ($i = 0; $i < $toSpawn; $i++) {
                try {
                    $this->spawn($queue, $options);
                } catch (\RuntimeException $e) {
                    $this->logger->warning('Failed to spawn worker during scaling', [
                        'error' => $e->getMessage(),
                        'queue' => $queue,
                        'attempted' => $i + 1,
                        'target' => $toSpawn,
                    ]);
                    break;
                }
            }
        } elseif ($count < $currentCount) {
            // Scale down
            $toStop = $currentCount - $count;
            $queueWorkers = $this->getWorkersByQueue($queue);
            $stopped = 0;

            foreach ($queueWorkers as $worker) {
                if ($stopped >= $toStop) {
                    break;
                }
                $this->stopWorker($worker->getWorkerId());
                $stopped++;
            }
        }

        $this->logger->info('Scaled workers', [
            'queue' => $queue,
            'from' => $currentCount,
            'to' => $this->getWorkerCount($queue),
        ]);
    }

    public function stopAll(int $timeout = 30): void
    {
        $this->logger->info('Stopping all workers', ['count' => count($this->workers)]);

        $stopPromises = [];
        foreach ($this->workers as $worker) {
            try {
                $worker->stop($timeout);
                $stopPromises[] = $worker->getWorkerId();
            } catch (\Exception $e) {
                $this->logger->error('Failed to stop worker', [
                    'worker_id' => $worker->getWorkerId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Wait for all workers to stop
        $deadline = time() + $timeout;
        while (count($this->workers) > 0 && time() < $deadline) {
            foreach ($this->workers as $workerId => $worker) {
                if (!$worker->isRunning()) {
                    unset($this->workers[$workerId]);
                }
            }
            usleep(100000); // 100ms
        }

        // Force kill any remaining workers
        foreach ($this->workers as $worker) {
            $worker->forceStop();
            unset($this->workers[$worker->getWorkerId()]);
        }

        $this->logger->info('All workers stopped');
    }

    public function restart(string $workerId): void
    {
        if (!isset($this->workers[$workerId])) {
            throw new \InvalidArgumentException("Worker {$workerId} not found");
        }

        $worker = $this->workers[$workerId];
        $queue = $worker->getQueue();
        $options = $worker->getOptions();

        $this->logger->info('Restarting worker', ['worker_id' => $workerId]);

        // Stop the worker
        $this->stopWorker($workerId);

        // Wait before restarting
        sleep($this->config['restart_delay']);

        // Spawn a new worker with the same configuration
        $this->spawn($queue, $options);
    }

    public function getStatus(): array
    {
        $status = [];

        foreach ($this->workers as $worker) {
            $status[] = [
                'id' => $worker->getWorkerId(),
                'queue' => $worker->getQueue(),
                'pid' => $worker->getPid(),
                'status' => $worker->isRunning() ? 'running' : 'stopped',
                'memory_usage' => $worker->getMemoryUsage(),
                'cpu_usage' => $worker->getCpuUsage(),
                'jobs_processed' => $worker->getJobsProcessed(),
                'started_at' => $worker->getStartedAt(),
                'last_heartbeat' => $worker->getLastHeartbeat(),
            ];
        }

        return $status;
    }

    public function monitorHealth(): void
    {
        foreach ($this->workers as $worker) {
            if (!$worker->isHealthy()) {
                $this->logger->warning('Unhealthy worker detected', [
                    'worker_id' => $worker->getWorkerId(),
                    'last_heartbeat' => $worker->getLastHeartbeat(),
                ]);

                // Attempt to restart unhealthy workers
                try {
                    $this->restart($worker->getWorkerId());
                } catch (\Exception $e) {
                    $this->logger->error('Failed to restart unhealthy worker', [
                        'worker_id' => $worker->getWorkerId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    public function getWorkerCount(string $queue = null): int
    {
        if ($queue === null) {
            return count($this->workers);
        }

        return count($this->getWorkersByQueue($queue));
    }

    public function getWorker(string $workerId): ?WorkerProcess
    {
        return $this->workers[$workerId] ?? null;
    }

    private function getWorkersByQueue(string $queue): array
    {
        return array_filter($this->workers, function (WorkerProcess $worker) use ($queue) {
            return $worker->getQueue() === $queue;
        });
    }

    private function stopWorker(string $workerId): void
    {
        if (!isset($this->workers[$workerId])) {
            return;
        }

        $worker = $this->workers[$workerId];

        try {
            $worker->stop();
            $this->logger->info('Worker stopped', ['worker_id' => $workerId]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to stop worker gracefully', [
                'worker_id' => $workerId,
                'error' => $e->getMessage(),
            ]);
            $worker->forceStop();
        }

        unset($this->workers[$workerId]);
    }

    public function __destruct()
    {
        // Ensure all workers are stopped when the manager is destroyed
        if (count($this->workers) > 0) {
            $this->stopAll();
        }
    }
}
