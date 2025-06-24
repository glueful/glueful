<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Glueful\Queue\Process\ProcessManager;
use Glueful\Queue\Process\ProcessFactory;
use Glueful\Queue\WorkerOptions;
use Glueful\Queue\Monitoring\WorkerMonitor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Queue Work Command
 * - Modern queue management with multi-worker support using Symfony Process
 * - Advanced worker monitoring and health checks
 * - Dynamic scaling and process management
 * - Real-time status monitoring with auto-refresh
 * - Graceful shutdown handling with signal management
 * @package Glueful\Console\Commands\Queue
 */
#[AsCommand(
    name: 'queue:work',
    description: 'Start queue workers with multi-worker support'
)]
class WorkCommand extends BaseQueueCommand
{
    private ProcessManager $processManager;
    private WorkerMonitor $workerMonitor;

    protected function configure(): void
    {
        $this->setDescription('Start queue workers with multi-worker support')
             ->setHelp('This command starts queue workers with modern process management, ' .
                      'multi-worker support, and advanced monitoring capabilities.')
             ->addArgument(
                 'action',
                 InputArgument::OPTIONAL,
                 'Action to perform (work, spawn, scale, status, stop, restart, health)',
                 'work'
             )
             ->addOption(
                 'workers',
                 'w',
                 InputOption::VALUE_REQUIRED,
                 'Number of workers to spawn',
                 '2'
             )
             ->addOption(
                 'queue',
                 'q',
                 InputOption::VALUE_REQUIRED,
                 'Queue(s) to process (comma-separated)',
                 'default'
             )
             ->addOption(
                 'memory',
                 'm',
                 InputOption::VALUE_REQUIRED,
                 'Memory limit per worker in MB',
                 '128'
             )
             ->addOption(
                 'timeout',
                 't',
                 InputOption::VALUE_REQUIRED,
                 'Job timeout in seconds',
                 '60'
             )
             ->addOption(
                 'max-jobs',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Max jobs per worker before restart',
                 '1000'
             )
             ->addOption(
                 'daemon',
                 'd',
                 InputOption::VALUE_NONE,
                 'Run in daemon mode (keep running)'
             )
             ->addOption(
                 'count',
                 'c',
                 InputOption::VALUE_REQUIRED,
                 'Number of workers to spawn/scale (for spawn/scale actions)',
                 '1'
             )
             ->addOption(
                 'worker-id',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Specific worker ID (for stop/restart actions)'
             )
             ->addOption(
                 'all',
                 'a',
                 InputOption::VALUE_NONE,
                 'Apply to all workers (for stop/restart actions)'
             )
             ->addOption(
                 'json',
                 'j',
                 InputOption::VALUE_NONE,
                 'Output status as JSON'
             )
             ->addOption(
                 'watch',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Auto-refresh interval in seconds (for status action)'
             )
             ->addOption(
                 'stop-when-empty',
                 null,
                 InputOption::VALUE_NONE,
                 'Stop when queue is empty'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $action = $input->getArgument('action');

        try {
            return match ($action) {
                'work' => $this->executeWork($input),
                'spawn' => $this->executeSpawn($input),
                'scale' => $this->executeScale($input),
                'status' => $this->executeStatus($input),
                'stop' => $this->executeStop($input),
                'restart' => $this->executeRestart($input),
                'health' => $this->executeHealth($input),
                default => $this->handleUnknownAction($action)
            };
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            if ($input->getOption('verbose')) {
                $this->error($e->getTraceAsString());
            }
            return self::FAILURE;
        }
    }

    private function initializeServices(): void
    {
        $logger = $this->getService(LoggerInterface::class);
        $basePath = dirname(__DIR__, 5); // Path to glueful root

        $processFactory = new ProcessFactory($logger, $basePath);
        $this->workerMonitor = $this->getService(WorkerMonitor::class);
        $this->processManager = new ProcessManager(
            $processFactory,
            $this->workerMonitor,
            $logger,
            config('queue.workers.process', [])
        );
    }

    private function executeWork(InputInterface $input): int
    {
        $workerCount = (int) $input->getOption('workers');
        $queues = $this->parseQueues($input->getOption('queue'));
        $memory = (int) $input->getOption('memory');
        $timeout = (int) $input->getOption('timeout');
        $maxJobs = (int) $input->getOption('max-jobs');
        $daemon = $input->getOption('daemon');
        $stopWhenEmpty = $input->getOption('stop-when-empty');

        $this->info("🚀 Starting queue workers...");
        $this->line("Workers: {$workerCount} (multi-worker enabled by default)");
        $this->line("Queue(s): " . implode(', ', $queues));
        $this->line("Memory limit: {$memory} MB per worker");
        $this->line();

        // Create worker options
        $workerOptions = new WorkerOptions(
            sleep: 3,
            memory: $memory,
            timeout: $timeout,
            maxJobs: $maxJobs,
            stopWhenEmpty: $stopWhenEmpty,
            maxAttempts: 3
        );

        // Spawn workers for each queue
        foreach ($queues as $queue) {
            $queue = trim($queue);
            $this->processManager->scale($workerCount, $queue, $workerOptions);
        }

        $this->success("Spawned {$workerCount} worker(s) per queue");

        // Monitor workers if not in daemon mode
        if (!$daemon) {
            return $this->monitorWorkers();
        }

        return self::SUCCESS;
    }

    private function executeSpawn(InputInterface $input): int
    {
        $count = (int) $input->getOption('count');
        $queue = $input->getOption('queue') ?: 'default';

        $this->info("➕ Spawning {$count} worker(s) for queue: {$queue}");

        $workerOptions = $this->createWorkerOptionsFromInput($input);

        for ($i = 0; $i < $count; $i++) {
            try {
                $worker = $this->processManager->spawn($queue, $workerOptions);
                $this->success("Spawned worker: {$worker->getWorkerId()}");
            } catch (\Exception $e) {
                $this->error("Failed to spawn worker: " . $e->getMessage());
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function executeScale(InputInterface $input): int
    {
        $count = (int) $input->getOption('count');
        $queue = $input->getOption('queue') ?: 'default';

        $currentCount = $this->processManager->getWorkerCount($queue);
        $this->info("📊 Scaling workers for queue: {$queue}");
        $this->line("Current: {$currentCount} → Target: {$count}");

        $workerOptions = $this->createWorkerOptionsFromInput($input);
        $this->processManager->scale($count, $queue, $workerOptions);

        $newCount = $this->processManager->getWorkerCount($queue);
        $this->success("Scaled to {$newCount} worker(s)");

        return self::SUCCESS;
    }

    private function executeStatus(InputInterface $input): int
    {
        $json = $input->getOption('json');
        $watch = $input->getOption('watch');

        if ($watch) {
            return $this->watchStatus((int) $watch, $json);
        }

        $status = $this->processManager->getStatus();

        if ($json) {
            $this->displayJson($status);
        } else {
            $this->displayWorkerStatus($status);
        }

        return self::SUCCESS;
    }

    private function executeStop(InputInterface $input): int
    {
        $timeout = 30; // Default timeout
        $all = $input->getOption('all');
        $workerId = $input->getOption('worker-id');

        if ($all) {
            $this->info("🛑 Stopping all workers...");
            $this->processManager->stopAll($timeout);
            $this->success("All workers stopped");
        } elseif ($workerId) {
            $this->info("🛑 Stopping worker: {$workerId}");
            $worker = $this->processManager->getWorker($workerId);
            if ($worker) {
                $worker->stop($timeout);
                $this->success("Worker stopped");
            } else {
                $this->error("Worker not found: {$workerId}");
                return self::FAILURE;
            }
        } else {
            $this->error("Please specify --all or --worker-id=ID");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function executeRestart(InputInterface $input): int
    {
        $all = $input->getOption('all');
        $workerId = $input->getOption('worker-id');

        if ($all) {
            $this->info("🔄 Restarting all workers...");
            $workers = $this->processManager->getStatus();
            foreach ($workers as $workerInfo) {
                try {
                    $this->processManager->restart($workerInfo['id']);
                    $this->line("Restarted: {$workerInfo['id']}");
                } catch (\Exception $e) {
                    $this->error("Failed to restart {$workerInfo['id']}: " . $e->getMessage());
                }
            }
            $this->success("All workers restarted");
        } elseif ($workerId) {
            $this->info("🔄 Restarting worker: {$workerId}");
            try {
                $this->processManager->restart($workerId);
                $this->success("Worker restarted");
            } catch (\Exception $e) {
                $this->error("Failed to restart: " . $e->getMessage());
                return self::FAILURE;
            }
        } else {
            $this->error("Please specify --all or --worker-id=ID");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function executeHealth(InputInterface $input): int
    {
        $this->info("🏥 Checking worker health...");

        $this->processManager->monitorHealth();
        $status = $this->processManager->getStatus();

        $healthy = 0;
        $unhealthy = 0;

        foreach ($status as $worker) {
            if ($worker['status'] === 'running') {
                $healthy++;
            } else {
                $unhealthy++;
            }
        }

        $this->line("Healthy workers: {$healthy}");
        if ($unhealthy > 0) {
            $this->warning("Unhealthy workers: {$unhealthy} (restarted)");
        }

        return self::SUCCESS;
    }

    private function monitorWorkers(): int
    {
        $this->info("📊 Monitoring workers (Ctrl+C to exit)");
        $this->line();

        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$running) {
                $running = false;
            });
        }

        while ($running) {
            $this->clearScreen();
            $status = $this->processManager->getStatus();
            $this->displayWorkerStatus($status);

            sleep(5);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $this->line();
        $this->info("Stopping all workers...");
        $this->processManager->stopAll();

        return self::SUCCESS;
    }

    private function watchStatus(int $interval, bool $json): int
    {
        $this->info("👁️  Watching worker status (Ctrl+C to exit)");
        $this->line();

        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$running) {
                $running = false;
            });
        }

        while ($running) {
            if (!$json) {
                $this->clearScreen();
            }

            $status = $this->processManager->getStatus();

            if ($json) {
                $this->displayJson($status);
            } else {
                $this->displayWorkerStatus($status);
            }

            sleep($interval);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        return self::SUCCESS;
    }

    private function displayWorkerStatus(array $status): void
    {
        if (empty($status)) {
            $this->warning("No workers running");
            $this->line();
            $this->info("💡 Start workers with: php glueful queue:work");
            return;
        }

        $this->info("🔧 Multi-Worker Queue Status:");
        $this->line(str_repeat('-', 100));

        foreach ($status as $worker) {
            $this->line(sprintf(
                "ID: %s | Queue: %s | PID: %s | Status: %s | Memory: %s | Jobs: %d | Runtime: %s",
                substr($worker['id'], 0, 20) . '...',
                $worker['queue'],
                $worker['pid'] ?? 'N/A',
                $this->formatStatus($worker['status']),
                $this->formatBytes($worker['memory_usage']),
                $worker['jobs_processed'],
                $this->formatDuration($worker['runtime'] ?? 0)
            ));
        }

        $this->line();
        $totalWorkers = count($status);
        $runningWorkers = count(array_filter($status, fn($w) => $w['status'] === 'running'));
        $totalJobs = array_sum(array_column($status, 'jobs_processed'));

        $this->line("Summary: {$runningWorkers}/{$totalWorkers} workers running | {$totalJobs} jobs processed");
    }

    private function createWorkerOptionsFromInput(InputInterface $input): WorkerOptions
    {
        return new WorkerOptions(
            sleep: 3,
            memory: (int) $input->getOption('memory'),
            timeout: (int) $input->getOption('timeout'),
            maxJobs: (int) $input->getOption('max-jobs'),
            stopWhenEmpty: $input->getOption('stop-when-empty'),
            maxAttempts: 3
        );
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'running' => "● Running",
            'stopped' => "● Stopped",
            default => "● {$status}"
        };
    }

    private function handleUnknownAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line();
        $this->info("Available actions: work, spawn, scale, status, stop, restart, health");
        $this->line();
        $this->info("💡 The queue command now defaults to multi-worker mode.");
        $this->info("   Use 'php glueful queue:work' to start with 2 workers by default.");
        return self::FAILURE;
    }
}
