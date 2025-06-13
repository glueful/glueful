<?php

namespace Glueful\Console\Commands\Queue;

use Glueful\Console\Command;
use Glueful\Queue\QueueManager;
use Glueful\Queue\Worker;
use Glueful\Queue\WorkerOptions;
use Glueful\Queue\Monitoring\WorkerMonitor;
use Glueful\Queue\Failed\FailedJobProvider;
use Glueful\Queue\Config\ConfigManager;

/**
 * Unified Queue Command
 *
 * Provides all queue management functionality in a single command with subcommands.
 * Consolidates status, monitoring, configuration, and management operations.
 *
 * Usage:
 * php glueful queue [action] [options]
 *
 * Actions:
 * - work: Start processing queue jobs
 * - status: Show queue status and statistics
 * - monitor: Real-time monitoring with detailed metrics
 * - failed: Manage failed jobs (list, retry, forget, flush)
 * - config: Manage configuration (validate, test, show)
 * - drivers: List available queue drivers
 *
 * @package Glueful\Console\Commands\Queue
 */
class QueueCommand extends Command
{
    /**
     * Get command name
     *
     * @return string Command name
     */
    public function getName(): string
    {
        return 'queue';
    }

    /**
     * Get command description
     *
     * @return string Command description
     */
    public function getDescription(): string
    {
        return 'Unified queue management command';
    }

    /**
     * Get detailed help text
     *
     * @return string Help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Unified queue management command providing all queue functionality.

Usage:
  php glueful queue [action] [options]

Actions:
  work [options]        Start processing queue jobs
    --connection        Queue connection to use
    --queue             Comma-separated list of queues
    --memory            Memory limit in MB (default: 128)
    --timeout           Job timeout in seconds (default: 60)
    --tries             Maximum attempts (default: 3)
    --sleep             Sleep when no jobs (default: 3)
    --daemon            Run in daemon mode

  status [options]      Show queue status and statistics
    --connection        Specific connection (default: all)
    --watch             Auto-refresh every N seconds
    --json              Output as JSON

  monitor [options]     Real-time monitoring with detailed metrics
    --refresh           Refresh interval in seconds (default: 5)
    --workers           Show only worker status
    --performance       Show only performance metrics
    --failed            Show only failed job stats

  failed <sub> [opts]   Manage failed jobs
    list                List failed jobs
    retry <id|all>      Retry failed job(s)
    forget <id>         Remove failed job
    flush               Remove all failed jobs
    export              Export failed jobs data

  config <sub> [opts]   Manage configuration
    show                Show current configuration
    validate            Validate configuration
    test <connection>   Test specific connection

  drivers               List available queue drivers

Options:
  --format              Output format (table, json, yaml)
  --verbose             Show detailed information
  --help                Show this help message

Examples:
  php glueful queue work --daemon
  php glueful queue status --watch=5
  php glueful queue monitor --refresh=10
  php glueful queue failed list --limit=20
  php glueful queue failed retry all
  php glueful queue config validate
  php glueful queue config test redis
  php glueful queue drivers --verbose
HELP;
    }

    /**
     * Execute the command
     *
     * @param array $args Command arguments
     * @return int Exit code
     */
    public function execute(array $args = []): int
    {
        // Show help if requested or no action
        if (empty($args) || in_array($args[0] ?? '', ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        $action = array_shift($args);

        try {
            return match ($action) {
                'work' => $this->executeWork($args),
                'status' => $this->executeStatus($args),
                'monitor' => $this->executeMonitor($args),
                'failed' => $this->executeFailed($args),
                'config' => $this->executeConfig($args),
                'drivers' => $this->executeDrivers($args),
                default => $this->handleUnknownAction($action)
            };
        } catch (\Exception $e) {
            $this->error("Error: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Execute work action
     *
     * @param array $args Arguments
     * @return int Exit code
     */
    private function executeWork(array $args): int
    {
        $options = $this->parseWorkOptions($args);

        $this->info("🚀 Starting queue worker...");
        $this->line("Connection: " . ($options['connection'] ?? 'default'));
        $this->line("Queue(s): " . ($options['queue'] ?? 'default'));
        $this->line("Memory limit: " . $options['memory'] . " MB");
        $this->line();

        $queueManager = new QueueManager();
        $worker = new Worker($queueManager);

        $workerOptions = new WorkerOptions(
            sleep: (int) $options['sleep'],
            memory: (int) $options['memory'],
            timeout: (int) $options['timeout'],
            maxAttempts: (int) $options['tries']
        );

        $connection = $options['connection'] ?? 'default';
        $queue = $options['queue'] ?? 'default';

        if ($options['daemon']) {
            $this->info("Running in daemon mode. Press Ctrl+C to stop.");
            $worker->daemon($connection, $queue, $workerOptions);
        } else {
            // Process a single job
            $driver = $queueManager->connection($connection);
            $job = $driver->pop($queue);

            if ($job) {
                $this->info("Processing job: " . get_class($job));

                try {
                    // Execute the job
                    $job->fire();
                    $driver->delete($job);
                    $this->success("Job completed successfully");
                } catch (\Exception $e) {
                    $this->error("Job failed: " . $e->getMessage());
                    $driver->failed($job, $e);
                }
            } else {
                $this->info("No jobs available in queue: {$queue}");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Execute status action
     *
     * @param array $args Arguments
     * @return int Exit code
     */
    private function executeStatus(array $args): int
    {
        $options = $this->parseStatusOptions($args);
        $queueManager = new QueueManager();
        $monitor = new WorkerMonitor();

        if ($options['watch']) {
            return $this->watchStatus($queueManager, $monitor, $options);
        }

        $status = $this->gatherStatus($queueManager, $monitor, $options['connection']);

        if ($options['json']) {
            echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
        } else {
            $this->displayStatus($status);
        }

        return Command::SUCCESS;
    }

    /**
     * Execute monitor action
     *
     * @param array $args Arguments
     * @return int Exit code
     */
    private function executeMonitor(array $args): int
    {
        $options = $this->parseMonitorOptions($args);
        $monitor = new WorkerMonitor();
        $queueManager = new QueueManager();

        $this->info("📊 Queue Monitor");
        $this->line("Press Ctrl+C to exit");
        $this->line();

        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$running) {
                $running = false;
            });
        }

        while ($running) {
            $this->clearScreen();

            if (!$options['workers'] && !$options['performance'] && !$options['failed']) {
                // Show all
                $this->displayWorkers($monitor);
                $this->line();
                $this->displayPerformanceMetrics($monitor);
                $this->line();
                $this->displayFailedJobStats($queueManager);
            } else {
                if ($options['workers']) {
                    $this->displayWorkers($monitor);
                }
                if ($options['performance']) {
                    $this->displayPerformanceMetrics($monitor);
                }
                if ($options['failed']) {
                    $this->displayFailedJobStats($queueManager);
                }
            }

            sleep($options['refresh']);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Execute failed jobs action
     *
     * @param array $args Arguments
     * @return int Exit code
     */
    private function executeFailed(array $args): int
    {
        if (empty($args)) {
            $this->error("Please specify a subcommand: list, retry, forget, flush, export");
            return Command::FAILURE;
        }

        $subcommand = array_shift($args);
        $failedProvider = new FailedJobProvider();

        return match ($subcommand) {
            'list' => $this->listFailedJobs($failedProvider, $args),
            'retry' => $this->retryFailedJobs($failedProvider, $args),
            'forget' => $this->forgetFailedJob($failedProvider, $args),
            'flush' => $this->flushFailedJobs($failedProvider, $args),
            'export' => $this->exportFailedJobs($failedProvider, $args),
            default => $this->handleUnknownSubcommand('failed', $subcommand)
        };
    }

    /**
     * Execute config action
     *
     * @param array $args Arguments
     * @return int Exit code
     */
    private function executeConfig(array $args): int
    {
        if (empty($args)) {
            $args = ['show'];
        }

        $subcommand = array_shift($args);
        $configManager = new ConfigManager();

        return match ($subcommand) {
            'show' => $this->showConfig($configManager, $args),
            'validate' => $this->validateConfig($configManager),
            'test' => $this->testConnection($configManager, $args),
            default => $this->handleUnknownSubcommand('config', $subcommand)
        };
    }

    /**
     * Execute drivers action
     *
     * @param array $args Arguments
     * @return int Exit code
     */
    private function executeDrivers(array $args): int
    {
        $verbose = $this->hasOption($args, '--verbose');
        $format = $this->getOptionValue($args, '--format', 'table');

        $queueManager = new QueueManager();
        $registry = $queueManager->getDriverRegistry();
        $drivers = $registry->getAllDriverInfo();

        if ($format === 'json') {
            $output = [];
            foreach ($drivers as $name => $info) {
                $output[$name] = [
                    'version' => $info->version,
                    'author' => $info->author,
                    'description' => $info->description,
                    'features' => $info->supportedFeatures,
                    'dependencies' => $info->requiredDependencies
                ];
            }
            echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
        } else {
            $this->displayDrivers($drivers, $verbose);
        }

        return Command::SUCCESS;
    }

    // Helper methods...

    /**
     * Parse work options
     *
     * @param array $args Arguments
     * @return array Parsed options
     */
    private function parseWorkOptions(array $args): array
    {
        return [
            'connection' => $this->getOptionValue($args, '--connection'),
            'queue' => $this->getOptionValue($args, '--queue'),
            'memory' => $this->getOptionValue($args, '--memory', 128),
            'timeout' => $this->getOptionValue($args, '--timeout', 60),
            'tries' => $this->getOptionValue($args, '--tries', 3),
            'sleep' => $this->getOptionValue($args, '--sleep', 3),
            'daemon' => $this->hasOption($args, '--daemon')
        ];
    }

    /**
     * Parse status options
     *
     * @param array $args Arguments
     * @return array Parsed options
     */
    private function parseStatusOptions(array $args): array
    {
        return [
            'connection' => $this->getOptionValue($args, '--connection'),
            'watch' => (int) $this->getOptionValue($args, '--watch', 0),
            'json' => $this->hasOption($args, '--json')
        ];
    }

    /**
     * Parse monitor options
     *
     * @param array $args Arguments
     * @return array Parsed options
     */
    private function parseMonitorOptions(array $args): array
    {
        return [
            'refresh' => (int) $this->getOptionValue($args, '--refresh', 5),
            'workers' => $this->hasOption($args, '--workers'),
            'performance' => $this->hasOption($args, '--performance'),
            'failed' => $this->hasOption($args, '--failed')
        ];
    }

    /**
     * Get option value from arguments
     *
     * @param array $args Arguments
     * @param string $option Option name
     * @param mixed $default Default value
     * @return mixed Option value
     */
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

    /**
     * Check if option exists
     *
     * @param array $args Arguments
     * @param string $option Option name
     * @return bool True if option exists
     */
    private function hasOption(array $args, string $option): bool
    {
        return in_array($option, $args);
    }

    /**
     * Handle unknown action
     *
     * @param string $action Unknown action
     * @return int Exit code
     */
    private function handleUnknownAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line();
        $this->info("Available actions: work, status, monitor, failed, config, drivers");
        $this->info("Use 'php glueful queue --help' for more information");
        return Command::FAILURE;
    }

    /**
     * Handle unknown subcommand
     *
     * @param string $command Main command
     * @param string $subcommand Unknown subcommand
     * @return int Exit code
     */
    private function handleUnknownSubcommand(string $command, string $subcommand): int
    {
        $this->error("Unknown {$command} subcommand: {$subcommand}");
        $this->line();

        switch ($command) {
            case 'failed':
                $this->info("Available subcommands: list, retry, forget, flush, export");
                break;
            case 'config':
                $this->info("Available subcommands: show, validate, test");
                break;
        }

        return Command::FAILURE;
    }

    /**
     * Watch status with auto-refresh
     *
     * @param QueueManager $queueManager Queue manager
     * @param WorkerMonitor $monitor Worker monitor
     * @param array $options Options
     * @return int Exit code
     */
    private function watchStatus(QueueManager $queueManager, WorkerMonitor $monitor, array $options): int
    {
        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$running) {
                $running = false;
            });
        }

        while ($running) {
            $this->clearScreen();
            $status = $this->gatherStatus($queueManager, $monitor, $options['connection']);
            $this->displayStatus($status);

            sleep($options['watch']);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Gather status information
     *
     * @param QueueManager $queueManager Queue manager
     * @param WorkerMonitor $monitor Worker monitor
     * @param string|null $connection Connection name
     * @return array Status data
     */
    private function gatherStatus(QueueManager $queueManager, WorkerMonitor $monitor, ?string $connection = null): array
    {
        $connections = $connection ? [$connection] : $queueManager->getAvailableConnections();
        $activeWorkers = $monitor->getActiveWorkers();
        $performanceStats = $monitor->getPerformanceStats();

        $status = [
            'timestamp' => date('Y-m-d H:i:s'),
            'connections' => [],
            'workers' => [
                'active' => count($activeWorkers),
                'total' => count($activeWorkers)
            ],
            'jobs' => [
                'processed' => $performanceStats['total_jobs'] ?? 0,
                'failed' => $performanceStats['failed_jobs'] ?? 0,
                'processing_time' => $performanceStats['avg_processing_time'] ?? 0
            ]
        ];

        foreach ($connections as $conn) {
            try {
                $driver = $queueManager->connection($conn);
                $health = $driver->healthCheck();

                $status['connections'][$conn] = [
                    'healthy' => $health->isHealthy(),
                    'message' => $health->message,
                    'pending' => $driver->size(),
                    'delayed' => $driver->size('delayed'),
                    'reserved' => $driver->size('reserved')
                ];
            } catch (\Exception $e) {
                $status['connections'][$conn] = [
                    'healthy' => false,
                    'message' => $e->getMessage(),
                    'pending' => 0,
                    'delayed' => 0,
                    'reserved' => 0
                ];
            }
        }

        return $status;
    }

    /**
     * Display status information
     *
     * @param array $status Status data
     * @return void
     */
    private function displayStatus(array $status): void
    {
        $this->info("📊 Queue Status");
        $this->line("Last updated: " . $status['timestamp']);
        $this->line();

        // Workers
        $this->info("Workers:");
        $this->line(sprintf(
            "  Active: %d | Total: %d",
            $status['workers']['active'],
            $status['workers']['total']
        ));
        $this->line();

        // Jobs
        $this->info("Jobs:");
        $this->line(sprintf(
            "  Processed: %d | Failed: %d | Avg Time: %.2fs",
            $status['jobs']['processed'],
            $status['jobs']['failed'],
            $status['jobs']['processing_time']
        ));
        $this->line();

        // Connections
        $this->info("Connections:");
        foreach ($status['connections'] as $name => $conn) {
            $health = $conn['healthy'] ? '✅' : '❌';
            $this->line(sprintf(
                "  %s %s: %d pending, %d delayed, %d reserved",
                $health,
                $name,
                $conn['pending'],
                $conn['delayed'],
                $conn['reserved']
            ));
            if (!$conn['healthy']) {
                $this->line("    Error: " . $conn['message']);
            }
        }
    }

    /**
     * Display workers
     *
     * @param WorkerMonitor $monitor Worker monitor
     * @return void
     */
    private function displayWorkers(WorkerMonitor $monitor): void
    {
        $this->info("👷 Workers");
        $this->line(str_repeat("─", 80));

        $workers = $monitor->getActiveWorkers();
        if (empty($workers)) {
            $this->line("No active workers");
            return;
        }

        $this->line(sprintf("%-20s %-15s %-20s %-10s %s", "Worker ID", "Status", "Current Job", "Memory", "Started"));
        $this->line(str_repeat("─", 80));

        foreach ($workers as $worker) {
            $this->line(sprintf(
                "%-20s %-15s %-20s %-10s %s",
                substr($worker['id'], 0, 20),
                $worker['status'],
                $worker['current_job'] ? substr($worker['current_job'], 0, 20) : '-',
                $this->formatBytes($worker['memory_usage']),
                date('H:i:s', $worker['started_at'])
            ));
        }
    }

    /**
     * Display performance metrics
     *
     * @param WorkerMonitor $monitor Worker monitor
     * @return void
     */
    private function displayPerformanceMetrics(WorkerMonitor $monitor): void
    {
        $this->info("📈 Performance Metrics");
        $this->line(str_repeat("─", 80));

        $stats = $monitor->getPerformanceStats();

        $metrics = [
            'Total Jobs Processed' => number_format($stats['total_jobs'] ?? 0),
            'Total Jobs Failed' => number_format($stats['failed_jobs'] ?? 0),
            'Average Processing Time' => number_format($stats['avg_processing_time'] ?? 0, 2) . 's',
            'Success Rate' => number_format($stats['success_rate'] ?? 0, 2) . '%',
            'Jobs/Hour' => number_format($stats['jobs_per_hour'] ?? 0),
            'Peak Memory Usage' => $this->formatBytes($stats['peak_memory'] ?? 0)
        ];

        foreach ($metrics as $label => $value) {
            $this->line(sprintf("%-25s: %s", $label, $value));
        }
    }

    /**
     * Display failed job statistics
     *
     * @param QueueManager $queueManager Queue manager
     * @return void
     */
    private function displayFailedJobStats(QueueManager $queueManager): void
    {
        $failedProvider = new FailedJobProvider();

        $this->info("❌ Failed Jobs");
        $this->line(str_repeat("─", 80));

        // Get failed job counts
        $allFailed = $failedProvider->all();
        $totalFailed = count($allFailed);

        // Calculate stats
        $today = 0;
        $thisWeek = 0;
        $byQueue = [];

        $now = time();
        $todayStart = strtotime('today');
        $weekStart = strtotime('monday this week');

        foreach ($allFailed as $job) {
            $failedAt = strtotime($job['failed_at']);

            if ($failedAt >= $todayStart) {
                $today++;
            }

            if ($failedAt >= $weekStart) {
                $thisWeek++;
            }

            $queue = $job['queue'] ?? 'default';
            $byQueue[$queue] = ($byQueue[$queue] ?? 0) + 1;
        }

        $stats = [
            'total' => $totalFailed,
            'today' => $today,
            'this_week' => $thisWeek,
            'by_queue' => $byQueue
        ];
        $this->line(sprintf("Total Failed: %d", $stats['total']));
        $this->line(sprintf("Failed Today: %d", $stats['today']));
        $this->line(sprintf("Failed This Week: %d", $stats['this_week']));

        if (!empty($stats['by_queue'])) {
            $this->line();
            $this->line("By Queue:");
            foreach ($stats['by_queue'] as $queue => $count) {
                $this->line(sprintf("  %s: %d", $queue, $count));
            }
        }
    }

    /**
     * Clear screen
     *
     * @return void
     */
    private function clearScreen(): void
    {
        echo "\033[2J\033[H";
    }

    /**
     * Format bytes
     *
     * @param int $bytes Bytes
     * @return string Formatted string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    // Failed job management methods...

    private function listFailedJobs(FailedJobProvider $provider, array $args): int
    {
        $limit = (int) $this->getOptionValue($args, '--limit', 20);
        $page = (int) $this->getOptionValue($args, '--page', 1);

        // Get all failed jobs
        $allFailed = $provider->all();

        // Apply pagination manually
        $offset = ($page - 1) * $limit;
        $failed = array_slice($allFailed, $offset, $limit);

        if (empty($failed)) {
            $this->info("No failed jobs found");
            return Command::SUCCESS;
        }

        $this->displayFailedJobs($failed);
        return Command::SUCCESS;
    }

    private function retryFailedJobs(FailedJobProvider $provider, array $args): int
    {
        if (empty($args)) {
            $this->error("Please specify job ID or 'all'");
            return Command::FAILURE;
        }

        $target = $args[0];

        if ($target === 'all') {
            $allFailed = $provider->all();
            $count = 0;
            foreach ($allFailed as $job) {
                $provider->retry($job['id']);
                $count++;
            }
            $this->success("Retried {$count} failed jobs");
        } else {
            $provider->retry($target);
            $this->success("Retried job {$target}");
        }

        return Command::SUCCESS;
    }

    private function forgetFailedJob(FailedJobProvider $provider, array $args): int
    {
        if (empty($args)) {
            $this->error("Please specify job ID");
            return Command::FAILURE;
        }

        $provider->forget($args[0]);
        $this->success("Removed failed job {$args[0]}");
        return Command::SUCCESS;
    }

    private function flushFailedJobs(FailedJobProvider $provider, array $args): int
    {
        if (!$this->confirm("Are you sure you want to remove all failed jobs?")) {
            return Command::SUCCESS;
        }

        $provider->flush();
        $this->success("All failed jobs have been removed");
        return Command::SUCCESS;
    }

    private function exportFailedJobs(FailedJobProvider $provider, array $args): int
    {
        $format = $this->getOptionValue($args, '--format', 'json');
        $output = $this->getOptionValue($args, '--output', 'failed_jobs.' . $format);

        $failed = $provider->all();

        if ($format === 'json') {
            file_put_contents($output, json_encode($failed, JSON_PRETTY_PRINT));
        } else {
            // CSV format
            $fp = fopen($output, 'w');
            fputcsv($fp, ['ID', 'Connection', 'Queue', 'Payload', 'Exception', 'Failed At']);
            foreach ($failed as $job) {
                fputcsv($fp, [
                    $job['id'],
                    $job['connection'],
                    $job['queue'],
                    $job['payload'],
                    $job['exception'],
                    $job['failed_at']
                ]);
            }
            fclose($fp);
        }

        $this->success("Exported " . count($failed) . " failed jobs to {$output}");
        return Command::SUCCESS;
    }

    private function displayFailedJobs(array $jobs): void
    {
        $this->info("Failed Jobs:");
        $this->line(str_repeat("─", 100));
        $this->line(sprintf("%-10s %-20s %-20s %-30s %s", "ID", "Queue", "Job", "Failed At", "Error"));
        $this->line(str_repeat("─", 100));

        foreach ($jobs as $job) {
            $payload = json_decode($job['payload'], true);
            $jobName = $payload['displayName'] ?? $payload['job'] ?? 'Unknown';
            $error = substr($job['exception'], 0, 30) . '...';

            $this->line(sprintf(
                "%-10s %-20s %-20s %-30s %s",
                $job['id'],
                $job['queue'],
                substr($jobName, 0, 20),
                date('Y-m-d H:i:s', strtotime($job['failed_at'])),
                $error
            ));
        }
    }

    // Config management methods...

    private function showConfig(ConfigManager $configManager, array $args): int
    {
        $format = $this->getOptionValue($args, '--format', 'table');
        $config = $configManager->all();

        if ($format === 'json') {
            echo json_encode($config, JSON_PRETTY_PRINT) . "\n";
        } else {
            $this->displayConfig($config);
        }

        return Command::SUCCESS;
    }

    private function validateConfig(ConfigManager $configManager): int
    {
        $this->info("🔍 Validating queue configuration...");

        $result = $configManager->validate();

        if ($result->isValid()) {
            $this->success("✅ Configuration is valid!");
        } else {
            $this->error("❌ Configuration validation failed!");
            $this->error($result->getErrorSummary());
        }

        if ($result->hasWarnings()) {
            $this->warning("⚠️  Warnings:");
            $this->warning($result->getWarningSummary());
        }

        return $result->isValid() ? Command::SUCCESS : Command::FAILURE;
    }

    private function testConnection(ConfigManager $configManager, array $args): int
    {
        if (empty($args)) {
            $this->error("Please specify a connection name");
            return Command::FAILURE;
        }

        $connectionName = $args[0];
        $this->info("🔗 Testing connection: {$connectionName}");

        try {
            $queueManager = new QueueManager();
            $health = $queueManager->testConnection($connectionName);

            if ($health['healthy']) {
                $this->success("✅ Connection test successful!");
                $this->line("Response time: " . $health['response_time'] . "ms");
            } else {
                $this->error("❌ Connection test failed!");
                $this->error($health['message']);
            }

            return $health['healthy'] ? Command::SUCCESS : Command::FAILURE;
        } catch (\Exception $e) {
            $this->error("Connection test failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function displayConfig(array $config): void
    {
        $this->info("⚙️  Queue Configuration");
        $this->line("═══════════════════════");
        $this->line();

        $this->line("Default Connection: " . ($config['default'] ?? 'not set'));
        $this->line();

        if (isset($config['connections']) && is_array($config['connections'])) {
            $this->info("Connections:");
            foreach ($config['connections'] as $name => $connection) {
                $driver = $connection['driver'] ?? 'unknown';
                $this->line("  • {$name} ({$driver})");
            }
        }
    }

    private function displayDrivers(array $drivers, bool $verbose): void
    {
        $this->info("🔌 Available Queue Drivers");
        $this->line("═════════════════════════");
        $this->line();

        foreach ($drivers as $name => $info) {
            $this->info("{$name} (v{$info->version})");
            $this->line("  Author: {$info->author}");
            $this->line("  Description: {$info->description}");

            if ($verbose) {
                $this->line("  Features: " . implode(', ', $info->supportedFeatures));
                if (!empty($info->requiredDependencies)) {
                    $this->line("  Dependencies: " . implode(', ', $info->requiredDependencies));
                }
            }
            $this->line();
        }
    }

    /**
     * Confirm action
     *
     * @param string $question Question to ask
     * @return bool True if confirmed
     */
    private function confirm(string $question): bool
    {
        $this->line($question . " (yes/no) [no]: ");
        $answer = trim(fgets(STDIN));
        return in_array(strtolower($answer), ['y', 'yes']);
    }
}
