<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Glueful\Console\Command;
use Glueful\Queue\Process\ProcessManager;
use Glueful\Queue\Process\ProcessFactory;
use Glueful\Queue\WorkerOptions;
use Glueful\Queue\Monitoring\WorkerMonitor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Queue Command with Process Management
 *
 * Modern queue command with multi-worker support using Symfony Process.
 *
 * @package Glueful\Console\Commands\Queue
 */
class QueueCommand extends Command
{
    private ProcessManager $processManager;
    private WorkerMonitor $workerMonitor;
    private ContainerInterface $container;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? container();

        $logger = $this->container->get(LoggerInterface::class);
        $basePath = dirname(__DIR__, 4); // Path to glueful root

        $processFactory = new ProcessFactory($logger, $basePath);
        $this->workerMonitor = $this->container->get(WorkerMonitor::class);
        $this->processManager = new ProcessManager(
            $processFactory,
            $this->workerMonitor,
            $logger,
            config('queue.workers.process', [])
        );
    }

    public function getName(): string
    {
        return 'queue';
    }

    public function getDescription(): string
    {
        return 'Queue management with multi-worker support (process-based)';
    }

    public function getHelp(): string
    {
        return <<<HELP
Modern queue management with multi-worker support and process management.

Usage:
  php glueful queue [action] [options]

Actions:
  work [options]        Start processing with workers (default: 2 workers)
    --workers           Number of workers to spawn (default: 2)
    --queue             Queue(s) to process (comma-separated)
    --memory            Memory limit per worker in MB
    --timeout           Job timeout in seconds
    --max-jobs          Max jobs per worker before restart
    --daemon            Run in daemon mode (keep running)
    
  spawn [options]       Spawn additional workers
    --count             Number of workers to spawn
    --queue             Queue to process
    
  scale [options]       Scale workers to specific count
    --count             Target number of workers
    --queue             Queue to scale
    
  status                Show status of all workers
    --json              Output as JSON
    --watch             Auto-refresh every N seconds
    
  stop [options]        Stop workers
    --all               Stop all workers
    --worker            Specific worker ID to stop
    --timeout           Graceful shutdown timeout (default: 30)
    
  restart [options]     Restart workers
    --all               Restart all workers
    --worker            Specific worker ID to restart
    
  health                Check worker health and restart unhealthy ones

Legacy Compatibility Commands:
  failed                Manage failed jobs (redirects to queue:failed)
  monitor               Real-time monitoring (redirects to queue:monitor)

Examples:
  php glueful queue work                              # Start 2 workers (default)
  php glueful queue work --workers=4 --queue=default,high
  php glueful queue spawn --count=2 --queue=emails
  php glueful queue scale --count=6 --queue=default
  php glueful queue status --watch=5
  php glueful queue stop --all

Note: This is the new process-based queue system. Multi-worker support is now the default.
HELP;
    }

    public function execute(array $args = []): int
    {
        if (empty($args) || in_array($args[0] ?? '', ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        $action = array_shift($args);

        try {
            return match ($action) {
                'work' => $this->executeWork($args),
                'spawn' => $this->executeSpawn($args),
                'scale' => $this->executeScale($args),
                'status' => $this->executeStatus($args),
                'stop' => $this->executeStop($args),
                'restart' => $this->executeRestart($args),
                'health' => $this->executeHealth($args),

                // Legacy compatibility redirects
                'failed' => $this->executeLegacyRedirect('queue:failed', $args),
                'monitor' => $this->executeLegacyRedirect('queue:monitor', $args),
                'config' => $this->executeLegacyRedirect('queue:config', $args),
                'drivers' => $this->executeLegacyRedirect('queue:drivers', $args),

                default => $this->handleUnknownAction($action)
            };
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            if ($this->hasOption($args, '--verbose')) {
                $this->error($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function executeWork(array $args): int
    {
        $options = $this->parseWorkOptions($args);
        $workerCount = (int) ($options['workers'] ?? $this->getDefaultWorkerCount());
        $queues = explode(',', $options['queue'] ?? 'default');

        $this->info("🚀 Starting queue workers...");
        $this->line("Workers: {$workerCount} (multi-worker enabled by default)");
        $this->line("Queue(s): " . implode(', ', $queues));
        $this->line("Memory limit: " . ($options['memory'] ?? 128) . " MB per worker");
        $this->line();

        // Create worker options
        $workerOptions = new WorkerOptions(
            sleep: (int) ($options['sleep'] ?? 3),
            memory: (int) ($options['memory'] ?? 128),
            timeout: (int) ($options['timeout'] ?? 60),
            maxJobs: (int) ($options['max-jobs'] ?? 1000),
            stopWhenEmpty: $this->hasOption($args, '--stop-when-empty'),
            maxAttempts: (int) ($options['max-attempts'] ?? 3)
        );

        // Spawn workers for each queue
        foreach ($queues as $queue) {
            $queue = trim($queue);
            $this->processManager->scale($workerCount, $queue, $workerOptions);
        }

        $this->success("Spawned {$workerCount} worker(s) per queue");

        // Monitor workers if not in daemon mode
        if (!$this->hasOption($args, '--daemon')) {
            return $this->monitorWorkers();
        }

        return Command::SUCCESS;
    }

    private function executeSpawn(array $args): int
    {
        $count = (int) $this->getOptionValue($args, '--count', 1);
        $queue = $this->getOptionValue($args, '--queue', 'default');

        $this->info("➕ Spawning {$count} worker(s) for queue: {$queue}");

        $workerOptions = $this->createWorkerOptionsFromArgs($args);

        for ($i = 0; $i < $count; $i++) {
            try {
                $worker = $this->processManager->spawn($queue, $workerOptions);
                $this->success("Spawned worker: {$worker->getWorkerId()}");
            } catch (\Exception $e) {
                $this->error("Failed to spawn worker: " . $e->getMessage());
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    private function executeScale(array $args): int
    {
        $count = (int) $this->getOptionValue($args, '--count', 1);
        $queue = $this->getOptionValue($args, '--queue', 'default');

        $currentCount = $this->processManager->getWorkerCount($queue);
        $this->info("📊 Scaling workers for queue: {$queue}");
        $this->line("Current: {$currentCount} → Target: {$count}");

        $workerOptions = $this->createWorkerOptionsFromArgs($args);
        $this->processManager->scale($count, $queue, $workerOptions);

        $newCount = $this->processManager->getWorkerCount($queue);
        $this->success("Scaled to {$newCount} worker(s)");

        return Command::SUCCESS;
    }

    private function executeStatus(array $args): int
    {
        $json = $this->hasOption($args, '--json');
        $watch = $this->getOptionValue($args, '--watch');

        if ($watch) {
            return $this->watchStatus((int) $watch, $json);
        }

        $status = $this->processManager->getStatus();

        if ($json) {
            echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
        } else {
            $this->displayWorkerStatus($status);
        }

        return Command::SUCCESS;
    }

    private function executeStop(array $args): int
    {
        $timeout = (int) $this->getOptionValue($args, '--timeout', 30);

        if ($this->hasOption($args, '--all')) {
            $this->info("🛑 Stopping all workers...");
            $this->processManager->stopAll($timeout);
            $this->success("All workers stopped");
        } elseif ($workerId = $this->getOptionValue($args, '--worker')) {
            $this->info("🛑 Stopping worker: {$workerId}");
            $worker = $this->processManager->getWorker($workerId);
            if ($worker) {
                $worker->stop($timeout);
                $this->success("Worker stopped");
            } else {
                $this->error("Worker not found: {$workerId}");
                return Command::FAILURE;
            }
        } else {
            $this->error("Please specify --all or --worker=ID");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function executeRestart(array $args): int
    {
        if ($this->hasOption($args, '--all')) {
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
        } elseif ($workerId = $this->getOptionValue($args, '--worker')) {
            $this->info("🔄 Restarting worker: {$workerId}");
            try {
                $this->processManager->restart($workerId);
                $this->success("Worker restarted");
            } catch (\Exception $e) {
                $this->error("Failed to restart: " . $e->getMessage());
                return Command::FAILURE;
            }
        } else {
            $this->error("Please specify --all or --worker=ID");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function executeHealth(array $args): int
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

        return Command::SUCCESS;
    }

    private function executeLegacyRedirect(string $newCommand, array $args): int
    {
        $this->warning("Legacy command detected. Please use: php glueful {$newCommand}");
        $this->info("The 'queue' command now focuses on multi-worker process management.");
        $this->line("For specialized features, use dedicated commands:");
        $this->line("  queue:failed   - Failed job management");
        $this->line("  queue:monitor  - Real-time monitoring");
        $this->line("  queue:config   - Configuration management");
        $this->line("  queue:autoscale - Auto-scaling features");

        return Command::SUCCESS;
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

        return Command::SUCCESS;
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
                echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
            } else {
                $this->displayWorkerStatus($status);
            }

            sleep($interval);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        return Command::SUCCESS;
    }

    private function displayWorkerStatus(array $status): void
    {
        if (empty($status)) {
            $this->warning("No workers running");
            $this->line();
            $this->info("💡 Start workers with: php glueful queue work");
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

    private function createWorkerOptionsFromArgs(array $args): WorkerOptions
    {
        return new WorkerOptions(
            sleep: (int) $this->getOptionValue($args, '--sleep', 3),
            memory: (int) $this->getOptionValue($args, '--memory', 128),
            timeout: (int) $this->getOptionValue($args, '--timeout', 60),
            maxJobs: (int) $this->getOptionValue($args, '--max-jobs', 1000),
            stopWhenEmpty: $this->hasOption($args, '--stop-when-empty'),
            maxAttempts: (int) $this->getOptionValue($args, '--max-attempts', 3)
        );
    }

    private function parseWorkOptions(array $args): array
    {
        return [
            'workers' => $this->getOptionValue($args, '--workers'),
            'queue' => $this->getOptionValue($args, '--queue', 'default'),
            'memory' => $this->getOptionValue($args, '--memory', 128),
            'timeout' => $this->getOptionValue($args, '--timeout', 60),
            'sleep' => $this->getOptionValue($args, '--sleep', 3),
            'max-jobs' => $this->getOptionValue($args, '--max-jobs', 1000),
            'max-attempts' => $this->getOptionValue($args, '--max-attempts', 3),
        ];
    }

    private function getDefaultWorkerCount(): int
    {
        return config('queue.workers.process.default_workers', 2); // Default to 2 workers
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            'running' => "● Running",
            'stopped' => "● Stopped",
            default => "● {$status}"
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1f KB', $bytes / 1024);
        }
        return sprintf('%.1f MB', $bytes / 1024 / 1024);
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            return sprintf('%dm %ds', floor($seconds / 60), $seconds % 60);
        }
        return sprintf('%dh %dm', floor($seconds / 3600), floor(($seconds % 3600) / 60));
    }

    private function handleUnknownAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line();
        $this->info("Available actions: work, spawn, scale, status, stop, restart, health");
        $this->line();
        $this->info("💡 The queue command now defaults to multi-worker mode.");
        $this->info("   Use 'php glueful queue work' to start with 2 workers by default.");
        return Command::FAILURE;
    }

    private function clearScreen(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            system('cls');
        } else {
            system('clear');
        }
    }

    // Helper methods for argument parsing
    private function getOptionValue(array $args, string $option, $default = null)
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, $option . '=')) {
                return substr($arg, strlen($option) + 1);
            }
        }

        $index = array_search($option, $args);
        if ($index !== false && isset($args[$index + 1]) && !str_starts_with($args[$index + 1], '--')) {
            return $args[$index + 1];
        }

        return $default;
    }

    private function hasOption(array $args, string $option): bool
    {
        return in_array($option, $args);
    }
}
