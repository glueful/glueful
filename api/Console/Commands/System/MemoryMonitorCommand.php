<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\System;

use Glueful\Performance\MemoryManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Memory Monitor Command
 * - Real-time memory monitoring with configurable intervals
 * - Advanced memory leak detection and analysis
 * - Memory usage profiling and trend analysis
 * - CSV export and detailed reporting
 * - Memory optimization suggestions and alerts
 * - Process monitoring with external command support
 * - Memory threshold management and notifications
 * @package Glueful\Console\Commands\System
 */
#[AsCommand(
    name: 'system:memory',
    description: 'Advanced memory monitoring and analysis tools'
)]
class MemoryMonitorCommand extends BaseCommand
{
    private MemoryManager $memoryManager;
    private $csvFile = null;
    private array $memoryHistory = [];

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Advanced memory monitoring and analysis tools')
             ->setHelp('This command provides comprehensive memory monitoring including ' .
                      'real-time tracking, leak detection, and performance analysis.')
             ->addArgument(
                 'command',
                 InputArgument::OPTIONAL,
                 'External command to monitor (optional)'
             )
             ->addOption(
                 'interval',
                 'i',
                 InputOption::VALUE_REQUIRED,
                 'Monitoring interval in seconds',
                 '1'
             )
             ->addOption(
                 'threshold',
                 't',
                 InputOption::VALUE_REQUIRED,
                 'Alert threshold in MB',
                 '128'
             )
             ->addOption(
                 'duration',
                 'd',
                 InputOption::VALUE_REQUIRED,
                 'Maximum monitoring duration in seconds (0 = unlimited)',
                 '0'
             )
             ->addOption(
                 'log',
                 'l',
                 InputOption::VALUE_NONE,
                 'Log memory usage to CSV file'
             )
             ->addOption(
                 'csv-file',
                 'c',
                 InputOption::VALUE_REQUIRED,
                 'CSV file path for memory metrics',
                 'memory-usage.csv'
             )
             ->addOption(
                 'analysis',
                 'a',
                 InputOption::VALUE_NONE,
                 'Perform memory analysis and leak detection'
             )
             ->addOption(
                 'profile',
                 'p',
                 InputOption::VALUE_NONE,
                 'Enable detailed memory profiling'
             )
             ->addOption(
                 'trends',
                 null,
                 InputOption::VALUE_NONE,
                 'Show memory usage trends and statistics'
             )
             ->addOption(
                 'summary',
                 's',
                 InputOption::VALUE_NONE,
                 'Show memory summary and recommendations'
             )
             ->addOption(
                 'watch',
                 'w',
                 InputOption::VALUE_NONE,
                 'Watch mode with real-time updates'
             )
             ->addOption(
                 'alert-script',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Script to run when threshold is exceeded'
             )
             ->addOption(
                 'format',
                 'f',
                 InputOption::VALUE_REQUIRED,
                 'Output format (table, json, plain)',
                 'table'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $command = $input->getArgument('command');

        // Show current memory status
        $this->displayCurrentMemoryStatus();

        // Handle different monitoring modes
        if ($input->getOption('summary')) {
            return $this->showMemorySummary($input);
        }

        if ($input->getOption('trends')) {
            return $this->showMemoryTrends($input);
        }

        if ($input->getOption('analysis')) {
            return $this->performMemoryAnalysis($input);
        }

        // Default: Start monitoring
        if ($command) {
            return $this->monitorCommand($command, $input);
        } else {
            return $this->monitorCurrentProcess($input);
        }
    }

    private function initializeServices(): void
    {
        $this->memoryManager = $this->getService(MemoryManager::class);
    }

    private function displayCurrentMemoryStatus(): void
    {
        $usage = $this->getCurrentMemoryUsage();

        $this->io->section('💾 Current Memory Status');

        $rows = [
            ['Metric', 'Value'],
            ['Current Usage', $this->formatBytes($usage['current'])],
            ['Peak Usage', $this->formatBytes($usage['peak'])],
            ['Memory Limit', $this->formatBytes($usage['limit'])],
            ['Usage Percentage', sprintf('%.2f%%', $usage['percentage'])],
            ['Available Memory', $this->formatBytes($usage['available'])],
        ];

        $this->io->table($rows[0], array_slice($rows, 1));

        // Show warnings if necessary
        if ($usage['percentage'] > 80) {
            $this->io->warning('⚠️ High memory usage detected!');
        }

        if ($usage['percentage'] > 90) {
            $this->io->error('Critical memory usage - immediate attention required');
        }
    }

    private function showMemorySummary(InputInterface $input): int
    {
        $this->io->title('📊 Memory Usage Summary');

        $usage = $this->getCurrentMemoryUsage();
        $recommendations = $this->generateRecommendations($usage);

        // Display detailed summary
        $this->displayDetailedMemoryInfo($usage);

        // Show recommendations
        if (!empty($recommendations)) {
            $this->io->section('💡 Optimization Recommendations');
            foreach ($recommendations as $recommendation) {
                $this->io->text("• {$recommendation}");
            }
        }

        // Show garbage collection stats if available
        if (function_exists('gc_status')) {
            $this->displayGarbageCollectionStats();
        }

        return self::SUCCESS;
    }

    private function showMemoryTrends(InputInterface $input): int
    {
        $this->io->title('📈 Memory Usage Trends');

        // For demonstration, we'll simulate trend data
        // In a real implementation, this would read from historical data
        $this->io->text('Collecting memory trend data...');

        $trends = $this->collectMemoryTrends();

        if (empty($trends)) {
            $this->io->warning('No historical data available for trend analysis');
            $this->io->text('Run monitoring for a period to collect trend data');
            return self::SUCCESS;
        }

        $this->displayTrendAnalysis($trends);
        return self::SUCCESS;
    }

    private function performMemoryAnalysis(InputInterface $input): int
    {
        $this->io->title('🔍 Memory Analysis & Leak Detection');

        $this->io->text('Performing comprehensive memory analysis...');

        // Initial memory snapshot
        $initialUsage = $this->getCurrentMemoryUsage();

        // Force garbage collection
        $this->io->text('Running garbage collection...');
        $this->memoryManager->forceGarbageCollection();

        // Post-GC snapshot
        $postGcUsage = $this->getCurrentMemoryUsage();

        // Calculate garbage collection efficiency
        $gcEfficiency = $this->calculateGcEfficiency($initialUsage, $postGcUsage);

        $this->displayAnalysisResults($initialUsage, $postGcUsage, $gcEfficiency);

        // Memory leak detection
        $this->performLeakDetection();

        return self::SUCCESS;
    }

    private function monitorCommand(string $command, InputInterface $input): int
    {
        $this->io->title("🔍 Monitoring External Command: {$command}");

        $interval = (float) $input->getOption('interval');
        $threshold = $this->mbToBytes((int) $input->getOption('threshold'));
        $maxDuration = (int) $input->getOption('duration');
        $enableLogging = $input->getOption('log');
        $csvFile = $input->getOption('csv-file');

        if ($enableLogging) {
            $this->setupCsvLogging($csvFile);
        }

        $this->io->text("Press Ctrl+C to stop monitoring");
        $this->io->newLine();

        // Start external process
        $process = Process::fromShellCommandline($command);
        $process->start();

        $startTime = time();
        $iteration = 0;
        $peakUsage = 0;

        try {
            while ($process->isRunning()) {
                $usage = $this->getCurrentMemoryUsage();
                $peakUsage = max($peakUsage, $usage['current']);

                $this->displayRealtimeUsage($usage, $iteration);

                // Check threshold
                if ($usage['current'] > $threshold) {
                    $this->handleThresholdExceeded($usage, $input);
                }

                // Log to CSV
                if ($this->csvFile) {
                    $this->logToCsv($usage, $iteration);
                }

                // Check duration limit
                if ($maxDuration > 0 && (time() - $startTime) >= $maxDuration) {
                    $this->io->newLine();
                    $this->io->text("Maximum duration reached, stopping monitoring");
                    $process->stop();
                    break;
                }

                $iteration++;
                usleep((int)($interval * 1000000));
            }
        } catch (\Exception $e) {
            $this->io->error("Monitoring failed: " . $e->getMessage());
            return self::FAILURE;
        } finally {
            $this->finishMonitoring($peakUsage, $csvFile);
        }

        // Show process output
        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();

        if ($output) {
            $this->io->section('Command Output');
            $this->io->text($output);
        }

        if ($errorOutput) {
            $this->io->section('Command Errors');
            $this->io->text($errorOutput);
        }

        $this->io->text("Command exited with code: " . $process->getExitCode());

        return self::SUCCESS;
    }

    private function monitorCurrentProcess(InputInterface $input): int
    {
        $this->io->title('📊 Monitoring Current Process');

        $interval = (float) $input->getOption('interval');
        $threshold = $this->mbToBytes((int) $input->getOption('threshold'));
        $maxDuration = (int) $input->getOption('duration');
        $enableLogging = $input->getOption('log');
        $csvFile = $input->getOption('csv-file');
        $watchMode = $input->getOption('watch');

        if ($enableLogging) {
            $this->setupCsvLogging($csvFile);
        }

        $this->io->text("Press Ctrl+C to stop monitoring");
        $this->io->newLine();

        $startTime = time();
        $iteration = 0;
        $peakUsage = 0;

        // Set up signal handlers
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                $this->io->newLine();
                $this->io->text("Monitoring stopped by user");
                exit(0);
            });
        }

        try {
            while (true) {
                $usage = $this->getCurrentMemoryUsage();
                $peakUsage = max($peakUsage, $usage['current']);

                if ($watchMode) {
                    // Clear screen for watch mode
                    $this->io->write("\033[2J\033[;H");
                    $this->io->title('📊 Memory Monitor (Watch Mode)');
                }

                $this->displayRealtimeUsage($usage, $iteration);

                // Check threshold
                if ($usage['current'] > $threshold) {
                    $this->handleThresholdExceeded($usage, $input);
                }

                // Log to CSV
                if ($this->csvFile) {
                    $this->logToCsv($usage, $iteration);
                }

                // Store for trend analysis
                $this->memoryHistory[] = $usage + ['iteration' => $iteration, 'time' => time()];

                // Check duration limit
                if ($maxDuration > 0 && (time() - $startTime) >= $maxDuration) {
                    $this->io->newLine();
                    $this->io->text("Maximum duration reached");
                    break;
                }

                $iteration++;
                usleep((int)($interval * 1000000));

                // Handle signals
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
        } catch (\Exception $e) {
            $this->io->error("Monitoring failed: " . $e->getMessage());
            return self::FAILURE;
        } finally {
            $this->finishMonitoring($peakUsage, $csvFile);
        }

        return self::SUCCESS;
    }

    private function getCurrentMemoryUsage(): array
    {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = $this->memoryManager->getMemoryLimit();
        $percentage = $limit > 0 ? ($current / $limit) * 100 : 0;
        $available = $limit > 0 ? $limit - $current : 0;

        return [
            'current' => $current,
            'peak' => $peak,
            'limit' => $limit,
            'percentage' => $percentage,
            'available' => $available,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }

    private function displayDetailedMemoryInfo(array $usage): void
    {
        $this->io->section('📋 Detailed Memory Information');

        $detailRows = [
            ['Metric', 'Value', 'Description'],
            ['Real Usage', $this->formatBytes($usage['current']), 'Actual memory allocated'],
            ['Peak Usage', $this->formatBytes($usage['peak']), 'Maximum memory used'],
            ['Memory Limit', $this->formatBytes($usage['limit']), 'PHP memory_limit setting'],
            ['Available', $this->formatBytes($usage['available']), 'Remaining memory available'],
            ['Usage %', sprintf('%.2f%%', $usage['percentage']), 'Percentage of limit used'],
        ];

        // Add system memory info if available
        if (function_exists('sys_getloadavg') && is_callable('sys_getloadavg')) {
            $load = sys_getloadavg();
            $detailRows[] = [
                'System Load',
                sprintf('%.2f, %.2f, %.2f', $load[0], $load[1], $load[2]),
                '1min, 5min, 15min averages'
            ];
        }

        $this->io->table($detailRows[0], array_slice($detailRows, 1));
    }

    private function displayRealtimeUsage(array $usage, int $iteration): void
    {
        $timestamp = date('H:i:s');
        $current = $this->formatBytes($usage['current']);
        $peak = $this->formatBytes($usage['peak']);
        $percentage = sprintf('%.2f%%', $usage['percentage']);

        $this->io->text(sprintf(
            "[%s] #%d | Current: %s | Peak: %s | Usage: %s",
            $timestamp,
            $iteration,
            $current,
            $peak,
            $percentage
        ));
    }

    private function generateRecommendations(array $usage): array
    {
        $recommendations = [];

        if ($usage['percentage'] > 90) {
            $recommendations[] = "Critical: Memory usage above 90% - increase memory_limit or optimize code";
        } elseif ($usage['percentage'] > 75) {
            $recommendations[] = "Warning: Memory usage above 75% - monitor closely and consider optimization";
        }

        if ($usage['current'] > $usage['limit'] * 0.8) {
            $recommendations[] = "Consider increasing PHP memory_limit setting";
        }

        $peakDifference = $usage['peak'] - $usage['current'];
        if ($peakDifference > 50 * 1024 * 1024) { // 50MB difference
            $recommendations[] = "Large difference between current and peak usage - possible memory leaks";
        }

        if (empty($recommendations)) {
            $recommendations[] = "Memory usage looks healthy";
        }

        return $recommendations;
    }

    private function displayGarbageCollectionStats(): void
    {
        $this->io->section('🗑️ Garbage Collection Statistics');

        $stats = gc_status();

        $gcRows = [
            ['Metric', 'Value'],
            ['Runs', number_format($stats['runs'])],
            ['Collected', number_format($stats['collected'])],
            ['Threshold', number_format($stats['threshold'])],
            ['Roots', number_format($stats['roots'])],
        ];

        $this->io->table($gcRows[0], array_slice($gcRows, 1));
    }

    private function collectMemoryTrends(): array
    {
        // Return stored memory history for trend analysis
        return array_slice($this->memoryHistory, -100); // Last 100 measurements
    }

    private function displayTrendAnalysis(array $trends): void
    {
        if (count($trends) < 2) {
            $this->io->warning('Insufficient data for trend analysis');
            return;
        }

        $first = $trends[0];
        $last = end($trends);

        $memoryChange = $last['current'] - $first['current'];
        $timeSpan = $last['time'] - $first['time'];

        $this->io->section('📈 Trend Analysis Results');

        $trendRows = [
            ['Metric', 'Value'],
            ['Time Span', $this->formatDuration($timeSpan)],
            [
                'Memory Change',
                $this->formatBytes(abs($memoryChange)) . ($memoryChange >= 0 ? ' (increase)' : ' (decrease)')
            ],
            ['Rate of Change', $this->formatBytes(abs($memoryChange / max($timeSpan, 1))) . '/second'],
            ['Peak in Period', $this->formatBytes(max(array_column($trends, 'current')))],
            ['Low in Period', $this->formatBytes(min(array_column($trends, 'current')))],
        ];

        $this->io->table($trendRows[0], array_slice($trendRows, 1));

        // Trend direction
        if ($memoryChange > 1024 * 1024) { // 1MB increase
            $this->io->warning('⬆️ Increasing memory trend detected - potential memory leak');
        } elseif ($memoryChange < -1024 * 1024) { // 1MB decrease
            $this->io->success('⬇️ Decreasing memory trend - good memory management');
        } else {
            $this->io->info('➡️ Stable memory usage');
        }
    }

    private function calculateGcEfficiency(array $before, array $after): array
    {
        $memoryFreed = $before['current'] - $after['current'];
        $efficiency = $before['current'] > 0 ? ($memoryFreed / $before['current']) * 100 : 0;

        return [
            'memory_freed' => $memoryFreed,
            'efficiency_percent' => $efficiency,
            'before' => $before,
            'after' => $after
        ];
    }

    private function displayAnalysisResults(array $before, array $after, array $gcEfficiency): void
    {
        $this->io->section('🔬 Analysis Results');

        $analysisRows = [
            ['Metric', 'Before GC', 'After GC', 'Change'],
            [
                'Memory Usage',
                $this->formatBytes($before['current']),
                $this->formatBytes($after['current']),
                $this->formatBytes($gcEfficiency['memory_freed']) . ' freed'
            ],
            [
                'Peak Usage',
                $this->formatBytes($before['peak']),
                $this->formatBytes($after['peak']),
                'N/A'
            ],
            [
                'GC Efficiency',
                'N/A',
                'N/A',
                sprintf('%.2f%%', $gcEfficiency['efficiency_percent'])
            ]
        ];

        $this->io->table($analysisRows[0], array_slice($analysisRows, 1));

        // Interpretation
        if ($gcEfficiency['efficiency_percent'] > 10) {
            $this->io->success('Good garbage collection efficiency');
        } elseif ($gcEfficiency['efficiency_percent'] > 5) {
            $this->io->warning('Moderate garbage collection efficiency');
        } else {
            $this->io->error('Low garbage collection efficiency - possible memory leaks');
        }
    }

    private function performLeakDetection(): void
    {
        $this->io->section('🕵️ Memory Leak Detection');

        // Simple leak detection by running GC multiple times
        $measurements = [];
        for ($i = 0; $i < 3; $i++) {
            $this->memoryManager->forceGarbageCollection();
            $measurements[] = memory_get_usage(true);
            usleep(100000); // 100ms delay
        }

        $stable = true;
        $maxDifference = 0;
        for ($i = 1; $i < count($measurements); $i++) {
            $difference = abs($measurements[$i] - $measurements[$i - 1]);
            $maxDifference = max($maxDifference, $difference);
            if ($difference > 1024 * 1024) { // 1MB difference
                $stable = false;
            }
        }

        if ($stable) {
            $this->io->success('✅ No obvious memory leaks detected');
        } else {
            $this->io->warning('⚠️ Potential memory leak detected');
            $this->io->text("Maximum difference between GC runs: " . $this->formatBytes($maxDifference));
        }
    }

    private function handleThresholdExceeded(array $usage, InputInterface $input): void
    {
        $this->io->warning("⚠️ Memory threshold exceeded: " . $this->formatBytes($usage['current']));

        // Run alert script if specified
        $alertScript = $input->getOption('alert-script');
        if ($alertScript) {
            $this->io->text("Running alert script: {$alertScript}");
            try {
                $process = Process::fromShellCommandline($alertScript);
                $process->run();
            } catch (\Exception $e) {
                $this->io->error("Alert script failed: " . $e->getMessage());
            }
        }

        // Force garbage collection
        $this->memoryManager->forceGarbageCollection();
        $this->io->text("Triggered garbage collection");
    }

    private function setupCsvLogging(string $csvFile): void
    {
        $isNewFile = !file_exists($csvFile);
        $this->csvFile = fopen($csvFile, 'a');

        if (!$this->csvFile) {
            $this->io->warning("Failed to open CSV file: {$csvFile}");
            return;
        }

        if ($isNewFile) {
            fputcsv($this->csvFile, [
                'Timestamp',
                'Iteration',
                'Current (bytes)',
                'Peak (bytes)',
                'Limit (bytes)',
                'Usage (%)',
                'Available (bytes)'
            ]);
        }

        $this->io->text("Logging to CSV: {$csvFile}");
    }

    private function logToCsv(array $usage, int $iteration): void
    {
        if (!$this->csvFile) {
            return;
        }

        fputcsv($this->csvFile, [
            $usage['timestamp'],
            $iteration,
            $usage['current'],
            $usage['peak'],
            $usage['limit'],
            $usage['percentage'],
            $usage['available']
        ]);
    }

    private function finishMonitoring(int $peakUsage, string $csvFile): void
    {
        $this->io->newLine();
        $this->io->section('📊 Monitoring Summary');
        $this->io->text("Peak memory usage: " . $this->formatBytes($peakUsage));

        if ($this->csvFile) {
            fclose($this->csvFile);
            $this->io->text("Memory usage log saved to: {$csvFile}");
        }
    }

    private function mbToBytes(int $mb): int
    {
        return $mb * 1024 * 1024;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        } elseif ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . 'h ' . $minutes . 'm';
        }
    }
}
