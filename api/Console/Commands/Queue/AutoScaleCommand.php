<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Glueful\Queue\Process\ProcessManager;
use Glueful\Queue\Process\ProcessFactory;
use Glueful\Queue\Process\AutoScaler;
use Glueful\Queue\Process\ScheduledScaler;
use Glueful\Queue\Process\ResourceMonitor;
use Glueful\Queue\Process\StreamingMonitor;
use Glueful\Queue\QueueManager;
use Glueful\Queue\Monitoring\WorkerMonitor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Queue Auto-Scale Command
 * Advanced queue auto-scaling command featuring:
 * - Advanced queue management with auto-scaling, scheduling, resource monitoring
 * - Real-time streaming capabilities and comprehensive metrics
 * - Intelligent resource-based scaling with trend analysis
 * - Schedule management with cron-like expressions
 * - Configuration validation and hot-reloading
 * @package Glueful\Console\Commands\Queue
 */
#[AsCommand(
    name: 'queue:autoscale',
    description: 'Advanced queue auto-scaling with monitoring and scheduling'
)]
class AutoScaleCommand extends BaseQueueCommand
{
    private ProcessManager $processManager;
    private AutoScaler $autoScaler;
    private ScheduledScaler $scheduledScaler;
    private ResourceMonitor $resourceMonitor;
    private StreamingMonitor $streamingMonitor;
    private array $config;

    protected function configure(): void
    {
        $this->setDescription('Advanced queue auto-scaling with monitoring and scheduling')
             ->setHelp('This command provides advanced queue auto-scaling with comprehensive ' .
                      'monitoring, scheduling, and real-time streaming capabilities.')
             ->addArgument(
                 'action',
                 InputArgument::OPTIONAL,
                 'Action to perform (run, status, config, schedule, resources, stream)',
                 'run'
             )
             ->addOption(
                 'interval',
                 'i',
                 InputOption::VALUE_REQUIRED,
                 'Check interval in seconds',
                 '60'
             )
             ->addOption(
                 'no-resource-checks',
                 null,
                 InputOption::VALUE_NONE,
                 'Disable resource monitoring'
             )
             ->addOption(
                 'no-scheduling',
                 null,
                 InputOption::VALUE_NONE,
                 'Disable scheduled scaling'
             )
             ->addOption(
                 'streaming',
                 's',
                 InputOption::VALUE_NONE,
                 'Enable real-time monitoring'
             )
             ->addOption(
                 'json',
                 'j',
                 InputOption::VALUE_NONE,
                 'Output as JSON'
             )
             ->addOption(
                 'detailed',
                 'd',
                 InputOption::VALUE_NONE,
                 'Show detailed metrics'
             )
             ->addOption(
                 'show',
                 null,
                 InputOption::VALUE_NONE,
                 'Show current configuration'
             )
             ->addOption(
                 'validate',
                 null,
                 InputOption::VALUE_NONE,
                 'Validate configuration'
             )
             ->addOption(
                 'reload',
                 null,
                 InputOption::VALUE_NONE,
                 'Reload configuration from file'
             )
             ->addOption(
                 'list',
                 'l',
                 InputOption::VALUE_NONE,
                 'List all schedules'
             )
             ->addOption(
                 'preview',
                 'p',
                 InputOption::VALUE_NONE,
                 'Preview upcoming schedule runs'
             )
             ->addOption(
                 'days',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Number of days to preview',
                 '7'
             )
             ->addOption(
                 'current',
                 null,
                 InputOption::VALUE_NONE,
                 'Show current resource usage'
             )
             ->addOption(
                 'history',
                 null,
                 InputOption::VALUE_NONE,
                 'Show resource usage history'
             )
             ->addOption(
                 'trends',
                 null,
                 InputOption::VALUE_NONE,
                 'Show resource usage trends'
             )
             ->addOption(
                 'format',
                 'f',
                 InputOption::VALUE_REQUIRED,
                 'Output format (text, json, table)',
                 'text'
             )
             ->addOption(
                 'filter',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Filter output (worker_id, level, message)'
             )
             ->addOption(
                 'export',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Export output to file'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $action = $input->getArgument('action');

        try {
            return match ($action) {
                'run' => $this->executeRun($input),
                'status' => $this->executeStatus($input),
                'config' => $this->executeConfig($input),
                'schedule' => $this->executeSchedule($input),
                'resources' => $this->executeResources($input),
                'stream' => $this->executeStream($input),
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
        $this->config = config('queue.workers', []);

        $logger = $this->getService(LoggerInterface::class);
        $queueManager = $this->getService(QueueManager::class);
        $workerMonitor = $this->getService(WorkerMonitor::class);
        $basePath = dirname(__DIR__, 5);

        $processFactory = new ProcessFactory($logger, $basePath);
        $this->processManager = new ProcessManager($processFactory, $workerMonitor, $logger, $this->config);

        $this->autoScaler = new AutoScaler($this->processManager, $queueManager, $logger, $this->config);
        $this->scheduledScaler = new ScheduledScaler($this->processManager, $logger);
        $this->resourceMonitor = new ResourceMonitor($logger, $this->config);
        $this->streamingMonitor = new StreamingMonitor($this->processManager, $logger);
    }

    private function executeRun(InputInterface $input): int
    {
        $interval = (int) $input->getOption('interval');
        $enableResourceChecks = !$input->getOption('no-resource-checks');
        $enableScheduling = !$input->getOption('no-scheduling');
        $enableStreaming = $input->getOption('streaming');

        $this->info("🚀 Starting auto-scaling daemon");
        $this->line("Check interval: {$interval} seconds");
        $this->line("Resource monitoring: " . ($enableResourceChecks ? 'enabled' : 'disabled'));
        $this->line("Scheduled scaling: " . ($enableScheduling ? 'enabled' : 'disabled'));
        $this->line("Real-time streaming: " . ($enableStreaming ? 'enabled' : 'disabled'));
        $this->line();

        // Load schedules
        if ($enableScheduling) {
            $this->scheduledScaler->loadSchedulesFromConfig($this->config);
            $this->success("Loaded scheduled scaling configuration");
        }

        // Setup signal handlers
        $running = true;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$running) {
                $running = false;
            });
            pcntl_signal(SIGTERM, function () use (&$running) {
                $running = false;
            });
        }

        $lastRun = 0;

        while ($running) {
            $currentTime = time();

            if ($currentTime - $lastRun >= $interval) {
                $this->performScalingCycle($enableResourceChecks, $enableScheduling);
                $lastRun = $currentTime;
            }

            if ($enableStreaming) {
                $this->displayStreamingStatus();
            }

            sleep(1);

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }

        $this->info("Auto-scaling daemon stopped");
        return self::SUCCESS;
    }

    private function performScalingCycle(bool $enableResourceChecks, bool $enableScheduling): void
    {
        $this->line(sprintf("[%s] Running scaling cycle", date('H:i:s')));

        // Resource monitoring
        if ($enableResourceChecks) {
            $this->resourceMonitor->recordResourceUsage();
            $resourceCheck = $this->resourceMonitor->shouldScaleDownForResources();

            if ($resourceCheck['should_scale_down']) {
                $this->warning("Resource constraints detected: " . implode(', ', $resourceCheck['reasons']));
            }
        }

        // Scheduled scaling
        if ($enableScheduling) {
            $scheduleResults = $this->scheduledScaler->processSchedules();
            foreach ($scheduleResults as $result) {
                $this->success(sprintf(
                    "Scheduled scaling: %s queue %s→%s workers (%s)",
                    $result['queue'],
                    $result['from_workers'],
                    $result['to_workers'],
                    $result['reason']
                ));
            }
        }

        // Auto-scaling based on load
        $scalingResults = $this->autoScaler->scale();
        foreach ($scalingResults as $result) {
            $this->success(sprintf(
                "Auto-scaled: %s queue %s→%s workers (%s)",
                $result['queue'],
                $result['from'],
                $result['to'],
                $result['reason']
            ));
        }
    }

    private function executeStatus(InputInterface $input): int
    {
        $json = $input->getOption('json');
        $detailed = $input->getOption('detailed');

        $status = [
            'auto_scaling' => [
                'enabled' => $this->config['auto_scale']['enabled'] ?? false,
                'history' => $this->autoScaler->getScalingHistory(),
            ],
            'scheduled_scaling' => [
                'schedules' => $this->scheduledScaler->getSchedules(),
                'stats' => $this->scheduledScaler->getScheduleStats(),
            ],
            'resources' => $this->resourceMonitor->getCurrentResources(),
            'workers' => $this->processManager->getStatus(),
        ];

        if ($detailed) {
            $status['resource_trends'] = $this->resourceMonitor->getResourceTrends();
            $status['resource_history'] = $this->resourceMonitor->getResourceHistory(10);
        }

        if ($json) {
            $this->displayJson($status);
        } else {
            $this->displayStatusReport($status, $detailed);
        }

        return self::SUCCESS;
    }

    private function executeConfig(InputInterface $input): int
    {
        if ($input->getOption('show')) {
            $this->displayConfiguration();
            return self::SUCCESS;
        }

        if ($input->getOption('validate')) {
            return $this->validateConfiguration();
        }

        if ($input->getOption('reload')) {
            $this->info("🔄 Reloading configuration...");
            $this->config = config('queue.workers', []);
            $this->success("Configuration reloaded successfully");
            return self::SUCCESS;
        }

        // Default: show configuration
        $this->displayConfiguration();
        return self::SUCCESS;
    }

    private function executeSchedule(InputInterface $input): int
    {
        if ($input->getOption('list')) {
            return $this->listSchedules();
        }

        if ($input->getOption('preview')) {
            $days = (int) $input->getOption('days');
            return $this->previewSchedules($days);
        }

        $this->error("Please specify an action: --list or --preview");
        return self::FAILURE;
    }

    private function executeResources(InputInterface $input): int
    {
        if ($input->getOption('current')) {
            $resources = $this->resourceMonitor->getCurrentResources();
            $this->displayResourceUsage($resources);
            return self::SUCCESS;
        }

        if ($input->getOption('history')) {
            $history = $this->resourceMonitor->getResourceHistory(20);
            $this->displayResourceHistory($history);
            return self::SUCCESS;
        }

        if ($input->getOption('trends')) {
            $trends = $this->resourceMonitor->getResourceTrends();
            $this->displayResourceTrends($trends);
            return self::SUCCESS;
        }

        // Default: show current resources
        $resources = $this->resourceMonitor->getCurrentResources();
        $this->displayResourceUsage($resources);
        return self::SUCCESS;
    }

    private function executeStream(InputInterface $input): int
    {
        $format = $input->getOption('format');
        $exportFile = $input->getOption('export');
        $filterStr = $input->getOption('filter');

        $filters = [];
        if ($filterStr) {
            $filters = $this->parseFilters($filterStr);
        }

        $this->info("Starting real-time stream monitoring");
        $this->line("Format: {$format}");
        if (!empty($filters)) {
            $this->line("Filters: " . json_encode($filters));
        }
        $this->line();

        // Setup signal handlers for graceful shutdown
        $streaming = true;
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () use (&$streaming, $exportFile) {
                $streaming = false;
                if ($exportFile) {
                    $this->streamingMonitor->exportOutput($exportFile);
                    echo "\nOutput exported to: {$exportFile}\n";
                }
            });
        }

        try {
            $this->streamingMonitor->startStreaming([
                'format' => $format,
                'filters' => $filters,
                'refresh_interval' => 1,
            ]);
        } catch (\Exception $e) {
            $this->error("Streaming failed: " . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function displayStatusReport(array $status, bool $detailed): void
    {
        $this->info("📊 Auto-scaling Status Report");
        $this->line(str_repeat('=', 50));

        // Auto-scaling status
        $autoEnabled = $status['auto_scaling']['enabled'] ? '✅ Enabled' : '❌ Disabled';
        $this->line("Auto-scaling: {$autoEnabled}");

        $historyCount = count($status['auto_scaling']['history']);
        $this->line("Recent scaling actions: {$historyCount}");

        // Scheduled scaling
        $scheduleCount = $status['scheduled_scaling']['stats']['total_schedules'];
        $enabledSchedules = $status['scheduled_scaling']['stats']['enabled_schedules'];
        $this->line("Schedules: {$enabledSchedules}/{$scheduleCount} enabled");

        // Resource usage
        $resources = $status['resources'];
        $this->line(sprintf(
            "Resources: %.1f%% CPU, %.1f%% Memory, %.1f%% Disk",
            $resources['cpu']['percentage'],
            $resources['memory']['percentage'],
            $resources['disk']['percentage']
        ));

        // Worker status
        $workerCount = count($status['workers']);
        $runningWorkers = count(array_filter($status['workers'], fn($w) => $w['status'] === 'running'));
        $this->line("Workers: {$runningWorkers}/{$workerCount} running");

        if ($detailed) {
            $this->line();
            $this->info("📈 Detailed Metrics");
            $this->displayDetailedMetrics($status);
        }
    }

    private function displayDetailedMetrics(array $status): void
    {
        // Recent scaling history
        $history = array_slice($status['auto_scaling']['history'], -5);
        if (!empty($history)) {
            $this->line("\nRecent scaling actions:");
            foreach ($history as $action) {
                $this->line(sprintf(
                    "  %s: %s %d→%d (%s)",
                    date('H:i:s', $action['timestamp']),
                    $action['queue'],
                    $action['from_workers'],
                    $action['to_workers'],
                    $action['reason']
                ));
            }
        }

        // Resource trends
        if (isset($status['resource_trends']) && !($status['resource_trends']['insufficient_data'] ?? false)) {
            $trends = $status['resource_trends'];
            $this->line("\nResource trends:");
            $this->line(sprintf(
                "  Memory: %+.1f%%, CPU: %+.1f%%, Load: %+.2f",
                $trends['memory_trend'],
                $trends['cpu_trend'],
                $trends['load_trend']
            ));
        }
    }

    private function displayConfiguration(): void
    {
        $this->info("⚙️  Auto-scaling Configuration");
        $this->line(str_repeat('=', 50));

        // Process management
        $processConfig = $this->config['process'] ?? [];
        $this->line("Process Management:");
        $this->line("  Enabled: " . ($processConfig['enabled'] ? 'Yes' : 'No'));
        $this->line("  Default workers: " . ($processConfig['default_workers'] ?? 2));
        $this->line("  Max workers per queue: " . ($processConfig['max_workers_per_queue'] ?? 10));

        // Auto-scaling
        $autoScaleConfig = $this->config['auto_scaling'] ?? [];
        $this->line("\nAuto-scaling:");
        $this->line("  Enabled: " . ($autoScaleConfig['enabled'] ? 'Yes' : 'No'));
        $this->line("  Scale up threshold: " . ($autoScaleConfig['scale_up_threshold'] ?? 100));
        $this->line("  Scale down threshold: " . ($autoScaleConfig['scale_down_threshold'] ?? 10));
        $this->line("  Cooldown period: " . ($autoScaleConfig['cooldown_period'] ?? 300) . "s");
    }

    private function validateConfiguration(): int
    {
        $this->info("🔍 Validating auto-scaling configuration...");
        $errors = [];
        $warnings = [];

        // Validate process config
        $processConfig = $this->config['process'] ?? [];
        if (empty($processConfig)) {
            $errors[] = "Process configuration is missing";
        } else {
            if (($processConfig['default_workers'] ?? 0) < 1) {
                $errors[] = "Default workers must be at least 1";
            }
            if (($processConfig['max_workers_per_queue'] ?? 0) < 1) {
                $errors[] = "Max workers per queue must be at least 1";
            }
        }

        // Validate auto-scaling config
        $autoScaleConfig = $this->config['auto_scaling'] ?? [];
        if ($autoScaleConfig['enabled'] ?? false) {
            $upThreshold = $autoScaleConfig['scale_up_threshold'] ?? 0;
            $downThreshold = $autoScaleConfig['scale_down_threshold'] ?? 0;

            if ($upThreshold <= $downThreshold) {
                $errors[] = "Scale up threshold must be greater than scale down threshold";
            }

            if (($autoScaleConfig['cooldown_period'] ?? 0) < 30) {
                $warnings[] = "Cooldown period less than 30 seconds may cause scaling oscillation";
            }
        }

        // Display results
        if (empty($errors) && empty($warnings)) {
            $this->success("✅ Configuration is valid");
            return self::SUCCESS;
        }

        if (!empty($errors)) {
            $this->error("❌ Configuration errors found:");
            foreach ($errors as $error) {
                $this->line("  • {$error}");
            }
        }

        if (!empty($warnings)) {
            $this->warning("⚠️  Configuration warnings:");
            foreach ($warnings as $warning) {
                $this->line("  • {$warning}");
            }
        }

        return empty($errors) ? self::SUCCESS : self::FAILURE;
    }

    private function listSchedules(): int
    {
        $schedules = $this->scheduledScaler->getSchedules();

        if (empty($schedules)) {
            $this->warning("No schedules configured");
            return self::SUCCESS;
        }

        $this->info("📅 Scaling Schedules");
        $this->line(str_repeat('=', 80));

        foreach ($schedules as $name => $schedule) {
            $status = $schedule['enabled'] ? '✅' : '❌';
            $nextRun = $schedule['next_run'] ?? 'Not scheduled';

            $this->line(sprintf(
                "%s %s | Queue: %s | Workers: %d | Cron: %s | Next: %s",
                $status,
                $name,
                $schedule['queue'],
                $schedule['workers'],
                $schedule['cron'],
                $nextRun
            ));
        }

        return self::SUCCESS;
    }

    private function previewSchedules(int $days): int
    {
        $preview = $this->scheduledScaler->previewSchedules($days);

        $this->info("📅 Schedule Preview (next {$days} days)");
        $this->line(str_repeat('=', 60));

        if (empty($preview)) {
            $this->warning("No scheduled runs in the next {$days} days");
            return self::SUCCESS;
        }

        foreach ($preview as $run) {
            $this->line(sprintf(
                "%s | %s: %s → %d workers",
                $run['run_time'],
                $run['schedule_name'],
                $run['queue'],
                $run['workers']
            ));
        }

        return self::SUCCESS;
    }

    private function displayResourceUsage(array $resources): void
    {
        $this->info("💻 System Resources");
        $this->line(str_repeat('=', 40));

        $this->line(sprintf(
            "Memory: %.1f%% (%.1f GB / %.1f GB)",
            $resources['memory']['percentage'],
            $resources['memory']['used'] / 1024 / 1024 / 1024,
            $resources['memory']['total'] / 1024 / 1024 / 1024
        ));

        $this->line(sprintf(
            "CPU: %.1f%% (%d cores)",
            $resources['cpu']['percentage'],
            $resources['cpu']['cores']
        ));

        $this->line(sprintf(
            "Load Average: %.2f",
            $resources['load_average']
        ));
    }

    private function displayResourceHistory(array $history): void
    {
        $this->info("📈 Resource Usage History");
        $this->line(str_repeat('=', 60));

        if (empty($history)) {
            $this->warning("No resource history available");
            return;
        }

        foreach (array_slice($history, -10) as $entry) {
            $timestamp = date('H:i:s', $entry['timestamp']);
            $this->line(sprintf(
                "%s | Memory: %5.1f%% | CPU: %5.1f%% | Load: %4.2f",
                $timestamp,
                $entry['memory']['percentage'],
                $entry['cpu']['percentage'],
                $entry['load_average']
            ));
        }
    }

    private function displayResourceTrends(array $trends): void
    {
        $this->info("📊 Resource Usage Trends");
        $this->line(str_repeat('=', 40));

        if ($trends['insufficient_data'] ?? false) {
            $this->warning("Insufficient data for trend analysis");
            return;
        }

        $this->line(sprintf("Memory trend: %+.1f%%", $trends['memory_trend']));
        $this->line(sprintf("CPU trend: %+.1f%%", $trends['cpu_trend']));
        $this->line(sprintf("Load trend: %+.2f", $trends['load_trend']));

        if ($trends['trending_up']) {
            $this->warning("⬆️  Resources are trending upward");
        } else {
            $this->info("⬇️  Resources are stable or trending downward");
        }
    }

    private function displayStreamingStatus(): void
    {
        // Simple streaming status display
        $status = $this->processManager->getStatus();
        $runningWorkers = count(array_filter($status, fn($w) => $w['status'] === 'running'));
        $totalWorkers = count($status);

        echo sprintf("\r[%s] Workers: %d/%d running", date('H:i:s'), $runningWorkers, $totalWorkers);
    }

    private function parseFilters(string $filterStr): array
    {
        $filters = [];
        $parts = explode(',', $filterStr);

        foreach ($parts as $part) {
            if (strpos($part, ':') !== false) {
                [$key, $value] = explode(':', $part, 2);
                $filters[trim($key)] = trim($value);
            }
        }

        return $filters;
    }

    private function handleUnknownAction(string $action): int
    {
        $this->error("Unknown action: {$action}");
        $this->line();
        $this->info("Available actions: run, status, config, schedule, resources, stream");
        return self::FAILURE;
    }
}
