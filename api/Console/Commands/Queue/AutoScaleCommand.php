<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Glueful\Console\Command;
use Glueful\Queue\Process\ProcessManager;
use Glueful\Queue\Process\ProcessFactory;
use Glueful\Queue\Process\AutoScaler;
use Glueful\Queue\Process\ScheduledScaler;
use Glueful\Queue\Process\ResourceMonitor;
use Glueful\Queue\Process\StreamingMonitor;
use Glueful\Queue\QueueManager;
use Glueful\Queue\Monitoring\WorkerMonitor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Auto-scaling Command for Queue Workers
 *
 * Advanced queue management with auto-scaling, scheduling, resource monitoring,
 * and real-time streaming capabilities.
 */
class AutoScaleCommand extends Command
{
    private ProcessManager $processManager;
    private AutoScaler $autoScaler;
    private ScheduledScaler $scheduledScaler;
    private ResourceMonitor $resourceMonitor;
    private StreamingMonitor $streamingMonitor;
    private ContainerInterface $container;
    private array $config;

    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? container();
        $this->config = config('queue.workers', []);

        $this->initializeServices();
    }

    private function initializeServices(): void
    {
        $logger = $this->container->get(LoggerInterface::class);
        $queueManager = $this->container->get(QueueManager::class);
        $workerMonitor = $this->container->get(WorkerMonitor::class);
        $basePath = dirname(__DIR__, 4);

        $processFactory = new ProcessFactory($logger, $basePath);
        $this->processManager = new ProcessManager($processFactory, $workerMonitor, $logger, $this->config);

        $this->autoScaler = new AutoScaler($this->processManager, $queueManager, $logger, $this->config);
        $this->scheduledScaler = new ScheduledScaler($this->processManager, $logger);
        $this->resourceMonitor = new ResourceMonitor($logger, $this->config);
        $this->streamingMonitor = new StreamingMonitor($this->processManager, $logger);
    }

    public function getName(): string
    {
        return 'queue:autoscale';
    }

    public function getDescription(): string
    {
        return 'Advanced queue auto-scaling with monitoring and scheduling';
    }

    public function getHelp(): string
    {
        return <<<HELP
Advanced queue auto-scaling with comprehensive monitoring and scheduling.

Usage:
  php glueful queue:autoscale [action] [options]

Actions:
  run [options]         Start the auto-scaling daemon
    --interval          Check interval in seconds (default: 60)
    --resource-checks   Enable resource monitoring (default: true)
    --scheduling        Enable scheduled scaling (default: true)
    --streaming         Enable real-time monitoring (default: false)
    
  status                Show auto-scaling status and metrics
    --json              Output as JSON
    --detailed          Show detailed metrics
    
  config                Show/manage auto-scaling configuration
    --show              Show current configuration
    --validate          Validate configuration
    --reload            Reload configuration from file
    
  schedule              Manage scaling schedules
    --list              List all schedules
    --add               Add a new schedule
    --remove            Remove a schedule
    --preview           Preview upcoming schedule runs
    
  resources             Monitor system resources
    --current           Show current resource usage
    --history           Show resource usage history
    --trends            Show resource usage trends
    
  stream [options]      Start real-time output streaming
    --format            Output format (text, json, table)
    --filter            Filter output (worker_id, level, message)
    --export            Export output to file

Examples:
  php glueful queue:autoscale run --interval=30 --streaming
  php glueful queue:autoscale status --detailed
  php glueful queue:autoscale schedule --preview --days=7
  php glueful queue:autoscale resources --trends
  php glueful queue:autoscale stream --format=table --filter=level:error
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
                'run' => $this->executeRun($args),
                'status' => $this->executeStatus($args),
                'config' => $this->executeConfig($args),
                'schedule' => $this->executeSchedule($args),
                'resources' => $this->executeResources($args),
                'stream' => $this->executeStream($args),
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

    private function executeRun(array $args): int
    {
        $interval = (int) $this->getOptionValue($args, '--interval', 60);
        $enableResourceChecks = !$this->hasOption($args, '--no-resource-checks');
        $enableScheduling = !$this->hasOption($args, '--no-scheduling');
        $enableStreaming = $this->hasOption($args, '--streaming');

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
        return Command::SUCCESS;
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

    private function executeStatus(array $args): int
    {
        $json = $this->hasOption($args, '--json');
        $detailed = $this->hasOption($args, '--detailed');

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
            echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
        } else {
            $this->displayStatusReport($status, $detailed);
        }

        return Command::SUCCESS;
    }

    private function executeSchedule(array $args): int
    {
        if ($this->hasOption($args, '--list')) {
            return $this->listSchedules();
        }

        if ($this->hasOption($args, '--preview')) {
            $days = (int) $this->getOptionValue($args, '--days', 7);
            return $this->previewSchedules($days);
        }

        if ($this->hasOption($args, '--add')) {
            return $this->addSchedule($args);
        }

        if ($this->hasOption($args, '--remove')) {
            $name = $this->getOptionValue($args, '--name');
            if (!$name) {
                $this->error("Schedule name is required for removal");
                return Command::FAILURE;
            }

            if ($this->scheduledScaler->removeSchedule($name)) {
                $this->success("Removed schedule: {$name}");
            } else {
                $this->error("Schedule not found: {$name}");
            }
            return Command::SUCCESS;
        }

        $this->error("Please specify an action: --list, --preview, --add, or --remove");
        return Command::FAILURE;
    }

    private function executeResources(array $args): int
    {
        if ($this->hasOption($args, '--current')) {
            $resources = $this->resourceMonitor->getCurrentResources();
            $this->displayResourceUsage($resources);
            return Command::SUCCESS;
        }

        if ($this->hasOption($args, '--history')) {
            $history = $this->resourceMonitor->getResourceHistory(20);
            $this->displayResourceHistory($history);
            return Command::SUCCESS;
        }

        if ($this->hasOption($args, '--trends')) {
            $trends = $this->resourceMonitor->getResourceTrends();
            $this->displayResourceTrends($trends);
            return Command::SUCCESS;
        }

        // Default: show current resources
        $resources = $this->resourceMonitor->getCurrentResources();
        $this->displayResourceUsage($resources);
        return Command::SUCCESS;
    }

    private function executeStream(array $args): int
    {
        $format = $this->getOptionValue($args, '--format', 'text');
        $exportFile = $this->getOptionValue($args, '--export');

        $filters = [];
        if ($filterStr = $this->getOptionValue($args, '--filter')) {
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
            return Command::FAILURE;
        }

        return Command::SUCCESS;
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

    private function listSchedules(): int
    {
        $schedules = $this->scheduledScaler->getSchedules();

        if (empty($schedules)) {
            $this->warning("No schedules configured");
            return Command::SUCCESS;
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

        return Command::SUCCESS;
    }

    private function previewSchedules(int $days): int
    {
        $preview = $this->scheduledScaler->previewSchedules($days);

        $this->info("📅 Schedule Preview (next {$days} days)");
        $this->line(str_repeat('=', 60));

        if (empty($preview)) {
            $this->warning("No scheduled runs in the next {$days} days");
            return Command::SUCCESS;
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

        return Command::SUCCESS;
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
            "Disk: %.1f%% (%.1f GB free)",
            $resources['disk']['percentage'],
            $resources['disk']['free'] / 1024 / 1024 / 1024
        ));

        $this->line(sprintf(
            "Load Average: %.2f",
            $resources['load_average']
        ));
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
        return Command::FAILURE;
    }

    private function executeConfig(array $args): int
    {
        if ($this->hasOption($args, '--show')) {
            $this->displayConfiguration();
            return Command::SUCCESS;
        }

        if ($this->hasOption($args, '--validate')) {
            return $this->validateConfiguration();
        }

        if ($this->hasOption($args, '--reload')) {
            $this->info("🔄 Reloading configuration...");
            $this->config = config('queue.workers', []);
            $this->success("Configuration reloaded successfully");
            return Command::SUCCESS;
        }

        // Default: show configuration
        $this->displayConfiguration();
        return Command::SUCCESS;
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

        // Queues
        $queues = $this->config['queues'] ?? [];
        if (!empty($queues)) {
            $this->line("\nQueue-specific settings:");
            foreach ($queues as $queueName => $queueConfig) {
                $this->line("  {$queueName}:");
                $this->line("    Workers: " . ($queueConfig['workers'] ?? 1));
                $this->line("    Max workers: " . ($queueConfig['max_workers'] ?? 5));
                $this->line("    Auto-scale: " . ($queueConfig['auto_scale'] ? 'Yes' : 'No'));
            }
        }
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
            return Command::SUCCESS;
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

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }

    private function displayStreamingStatus(): void
    {
        // Simple streaming status display
        $status = $this->processManager->getStatus();
        $runningWorkers = count(array_filter($status, fn($w) => $w['status'] === 'running'));
        $totalWorkers = count($status);

        echo sprintf("\r[%s] Workers: %d/%d running", date('H:i:s'), $runningWorkers, $totalWorkers);
    }

    private function addSchedule(array $args): int
    {
        $name = $this->getOptionValue($args, '--name');
        $cron = $this->getOptionValue($args, '--cron');
        $queue = $this->getOptionValue($args, '--queue');
        $workers = (int) $this->getOptionValue($args, '--workers');

        if (!$name || !$cron || !$queue || !$workers) {
            $this->error("Missing required options: --name, --cron, --queue, --workers");
            return Command::FAILURE;
        }

        if (!$this->scheduledScaler->validateCronExpression($cron)) {
            $this->error("Invalid cron expression: {$cron}");
            return Command::FAILURE;
        }

        $this->scheduledScaler->addSchedule($name, $cron, $queue, $workers);
        $this->success("Added schedule: {$name}");

        return Command::SUCCESS;
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

        $memoryTrend = $trends['memory_trend'];
        $cpuTrend = $trends['cpu_trend'];
        $loadTrend = $trends['load_trend'];

        $this->line(sprintf("Memory trend: %+.1f%%", $memoryTrend));
        $this->line(sprintf("CPU trend: %+.1f%%", $cpuTrend));
        $this->line(sprintf("Load trend: %+.2f", $loadTrend));

        if ($trends['trending_up']) {
            $this->warning("⬆️  Resources are trending upward");
        } else {
            $this->info("⬇️  Resources are stable or trending downward");
        }
    }

    // Helper methods from parent classes
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
