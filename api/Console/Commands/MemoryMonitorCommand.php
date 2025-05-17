<?php

declare(strict_types=1);

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Performance\MemoryManager;

/**
 * Memory Monitoring Command
 *
 * Provides command-line tools for monitoring memory usage:
 * - Monitor memory u    /**
     * Monitor memory usage of the current process
     *
     * @param float $interval Monitoring interval in seconds
     * @param int $threshold Alert threshold in bytes
     * @param int $maxDuration Maximum monitoring duration in seconds
     * @param string $csvPath Path to CSV file for logging
     * @return int Exit code
     */
class MemoryMonitorCommand extends Command
{
    /**
     * The name of the command
     */
    protected string $name = 'memory:monitor';

    /**
     * The description of the command
     */
    protected string $description = 'Monitor memory usage of a command or the current process';

    /**
     * The command syntax
     */
    protected string $syntax = 'memory:monitor [options] [command]';

    /**
     * Command options
     */
    protected array $options = [
        '--interval'   => 'Monitoring interval in seconds (default: 1)',
        '--threshold'  => 'Alert threshold in MB (default: 20)',
        '--duration'   => 'Maximum monitoring duration in seconds, 0 for unlimited (default: 0)',
        '--log'        => 'Log the memory usage to file',
        '--csv'        => 'CSV file to save memory metrics (default: memory-usage.csv)',
    ];

    /**
     * @var MemoryManager
     */
    protected MemoryManager $memoryManager;

    /**
     * @var resource|null
     */
    protected $csvFile = null;

    /**
     * Get the command name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get Command Description
     *
     * @return string Brief description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Execute the command
     *
     * @param array $args Command arguments
     * @param array $options Command options
     * @return int Exit code
     */
    public function execute(array $args = [], array $options = []): int
    {
        if (isset($args[0]) && in_array($args[0], ['-h', '--help', 'help'])) {
            $this->showHelp();
            return Command::SUCCESS;
        }

        // Get command to monitor (if specified)
        $command = $args[0] ?? null;

        // Parse options
        $interval = isset($options['interval']) ? (float) $options['interval'] : 1.0;
        $threshold = isset($options['threshold'])
            ? (int) $options['threshold'] * 1024 * 1024
            : 20 * 1024 * 1024; // Convert MB to bytes
        $maxDuration = isset($options['duration']) ? (int) $options['duration'] : 0;
        $shouldLog = isset($options['log']);
        $csvPath = $options['csv'] ?? 'memory-usage.csv';

        // Set up CSV logging if requested
        if ($shouldLog && $csvPath) {
            $this->setupCsvLogging($csvPath);
        }

        // Display initial memory usage
        $this->displayMemoryUsage();

        if ($command) {
            // Execute the specified command with memory monitoring
            return $this->monitorCommand($command, $interval, $threshold, $maxDuration, $csvPath);
        } else {
            // Monitor the current process
            return $this->monitorCurrentProcess($interval, $threshold, $maxDuration, $csvPath);
        }
    }

    /**
     * Display help information for this command
     *
     * @return void
     */
    protected function showHelp(): void
    {
        $this->info("\n{$this->description}");
        $this->line("\n<comment>Usage:</comment>");
        $this->line("  {$this->syntax}\n");

        $this->line("<comment>Options:</comment>");
        foreach ($this->options as $option => $description) {
            $this->line("  <info>{$option}</info>\t{$description}");
        }

        $this->line("\n<comment>Examples:</comment>");
        $this->line("  memory:monitor                          # Monitor the current process");
        $this->line("  memory:monitor php -v                   # Monitor the 'php -v' command");
        $this->line("  memory:monitor --interval=0.5 php -v    # Monitor every 0.5 seconds");
        $this->line("  memory:monitor --threshold=50 php -v    # Alert when usage exceeds 50MB");
        $this->line("  memory:monitor --log --csv=stats.csv    # Log results to stats.csv");
        $this->line("  memory:monitor --duration=60            # Monitor for 60 seconds max");
        $this->line("");
    }

    /**
     * Monitor memory usage while executing another command
     *
     * @param string $command The command to execute
     * @param float $interval Monitoring interval in seconds
     * @param int $threshold Alert threshold in bytes
     * @param int $maxDuration Maximum monitoring duration in seconds
     * @param string $csvPath Path to CSV file for logging
     * @return int Exit code
     */
    protected function monitorCommand(
        string $command,
        float $interval,
        int $threshold,
        int $maxDuration,
        string $csvPath
    ): int {
        $this->info("Starting memory monitoring for command: {$command}");
        $this->info("Press Ctrl+C to stop monitoring");

        // Start the command in a separate process
        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            $this->error("Failed to start command: {$command}");
            return 1;
        }

        $startTime = time();
        $highestUsage = 0;
        $iteration = 0;

        try {
            // Monitor while the process is running
            while (proc_get_status($process)['running']) {
                $usage = $this->displayMemoryUsage();
                $highestUsage = max($highestUsage, $usage['current']);

                if ($usage['current'] > $threshold) {
                    $this->warning("Memory usage exceeds threshold: " . $this->formatBytes($usage['current']));
                }

                // Check if we've reached the maximum duration
                if ($maxDuration > 0 && (time() - $startTime) >= $maxDuration) {
                    $this->info("Maximum monitoring duration reached");
                    break;
                }

                // Log to CSV if enabled
                if ($this->csvFile) {
                    $this->logToCsv($usage, $iteration++);
                }

                // Check if there's any output from the command
                $this->checkCommandOutput($pipes);

                usleep((int)($interval * 1000000)); // Convert seconds to microseconds
            }
        } finally {
            // Capture remaining output
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);

            // Close all pipes
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            // Get the exit code
            $exitCode = proc_close($process);

            if (!empty($stdout)) {
                $this->info("Command output:\n" . $stdout);
            }

            if (!empty($stderr)) {
                $this->error("Command errors:\n" . $stderr);
            }

            $this->info("Command exited with code: {$exitCode}");
            $this->info("Peak memory usage: " . $this->formatBytes($highestUsage));

            if ($this->csvFile) {
                fclose($this->csvFile);
                $this->info("Memory usage log saved to: {$csvPath}");
            }
        }

        return 0;
    }

    /**
     * Monitor memory usage of the current process
     *
     * @param float $interval Monitoring interval in seconds
     * @param int $threshold Alert threshold in bytes
     * @param int $maxDuration Maximum monitoring duration in seconds
     * @param string $csvPath Path to CSV file for logging
     * @return int Exit code
     */
    protected function monitorCurrentProcess(float $interval, int $threshold, int $maxDuration, string $csvPath): int
    {
        $this->info("Starting memory monitoring for current process");
        $this->info("Press Ctrl+C to stop monitoring");

        $startTime = time();
        $highestUsage = 0;
        $iteration = 0;

        try {
            while (true) {
                $usage = $this->displayMemoryUsage();
                $highestUsage = max($highestUsage, $usage['current']);

                if ($usage['current'] > $threshold) {
                    $this->warning("Memory usage exceeds threshold: " . $this->formatBytes($usage['current']));

                    // Trigger garbage collection if we exceed the threshold
                    $this->memoryManager->forceGarbageCollection();
                    $this->info("Garbage collection triggered");
                }

                // Check if we've reached the maximum duration
                if ($maxDuration > 0 && (time() - $startTime) >= $maxDuration) {
                    $this->info("Maximum monitoring duration reached");
                    break;
                }

                // Log to CSV if enabled
                if ($this->csvFile) {
                    $this->logToCsv($usage, $iteration++);
                }

                usleep((int)($interval * 1000000)); // Convert seconds to microseconds
            }
        } finally {
            $this->info("Peak memory usage: " . $this->formatBytes($highestUsage));

            if ($this->csvFile) {
                fclose($this->csvFile);
                $this->info("Memory usage log saved to: {$csvPath}");
            }
        }

        return 0;
    }

    /**
     * Display current memory usage
     *
     * @return array Memory usage statistics
     */
    protected function displayMemoryUsage(): array
    {
        $current = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        $limit = $this->memoryManager->getMemoryLimit();
        $percentage = $limit > 0 ? ($current / $limit) * 100 : 0;

        $usage = [
            'current' => $current,
            'peak' => $peak,
            'limit' => $limit,
            'percentage' => $percentage,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $this->line(sprintf(
            "Memory: %s / %s (%.2f%%) | Peak: %s",
            $this->formatBytes($current),
            $this->formatBytes($limit),
            $percentage,
            $this->formatBytes($peak)
        ));

        return $usage;
    }

    /**
     * Set up CSV logging
     *
     * @param string $csvPath Path to CSV file
     * @return void
     */
    protected function setupCsvLogging(string $csvPath): void
    {
        $isNewFile = !file_exists($csvPath);
        $this->csvFile = fopen($csvPath, 'a');

        if (!$this->csvFile) {
            $this->warning("Failed to open CSV file for logging: {$csvPath}");
            return;
        }

        // Write headers if this is a new file
        if ($isNewFile) {
            fputcsv($this->csvFile, [
                'Timestamp',
                'Iteration',
                'Current (bytes)',
                'Peak (bytes)',
                'Limit (bytes)',
                'Usage (%)'
            ]);
        }
    }

    /**
     * Log memory usage to CSV file
     *
     * @param array $usage Memory usage statistics
     * @param int $iteration Current iteration number
     * @return void
     */
    protected function logToCsv(array $usage, int $iteration): void
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
            $usage['percentage']
        ]);
    }

    /**
     * Check for output from the monitored command
     *
     * @param array $pipes Array of process pipes
     * @return void
     */
    protected function checkCommandOutput(array $pipes): void
    {
        $read = [$pipes[1], $pipes[2]];
        $write = null;
        $except = null;

        // Check if there's any data to read (with a timeout of 0)
        if (stream_select($read, $write, $except, 0)) {
            foreach ($read as $pipe) {
                if ($pipe === $pipes[1]) {
                    // stdout
                    $output = fgets($pipe);
                    if ($output !== false) {
                        $this->line(trim($output));
                    }
                } elseif ($pipe === $pipes[2]) {
                    // stderr
                    $output = fgets($pipe);
                    if ($output !== false) {
                        $this->error(trim($output));
                    }
                }
            }
        }
    }

    /**
     * Format bytes into a human-readable string
     *
     * @param int $bytes
     * @return string
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
