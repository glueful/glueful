<?php

declare(strict_types=1);

namespace Glueful\Queue\Process;

use Psr\Log\LoggerInterface;

/**
 * Streaming Monitor for Real-time Worker Output
 *
 * Provides real-time streaming of worker output, metrics, and status updates.
 * Supports multiple output formats and filtering capabilities.
 */
class StreamingMonitor
{
    private ProcessManager $processManager;
    private LoggerInterface $logger;
    private array $outputBuffer = [];
    private array $filters = [];
    private bool $isStreaming = false;
    private array $subscribers = [];

    public function __construct(ProcessManager $processManager, LoggerInterface $logger)
    {
        $this->processManager = $processManager;
        $this->logger = $logger;
    }

    /**
     * Start streaming worker output
     */
    public function startStreaming(array $options = []): void
    {
        $this->isStreaming = true;
        $this->filters = $options['filters'] ?? [];
        $refreshInterval = $options['refresh_interval'] ?? 1;
        $outputFormat = $options['format'] ?? 'text';

        $this->logger->info('Started streaming monitor', [
            'refresh_interval' => $refreshInterval,
            'format' => $outputFormat,
            'filters' => $this->filters,
        ]);

        while ($this->isStreaming) {
            $this->collectWorkerOutput();
            $this->displayStreaming($outputFormat);

            sleep($refreshInterval);

            // Handle signals for graceful shutdown
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Stop streaming
     */
    public function stopStreaming(): void
    {
        $this->isStreaming = false;
        $this->logger->info('Stopped streaming monitor');
    }

    /**
     * Collect output from all workers
     */
    private function collectWorkerOutput(): void
    {
        $workers = $this->processManager->getStatus();

        foreach ($workers as $workerInfo) {
            $workerId = $workerInfo['id'];
            $worker = $this->processManager->getWorker($workerId);

            if ($worker && $worker->isRunning()) {
                $output = $worker->getOutput();
                $errorOutput = $worker->getErrorOutput();

                if (!empty($output)) {
                    $this->processWorkerOutput($workerId, $output, 'stdout');
                }

                if (!empty($errorOutput)) {
                    $this->processWorkerOutput($workerId, $errorOutput, 'stderr');
                }
            }
        }
    }

    /**
     * Process and filter worker output
     */
    private function processWorkerOutput(string $workerId, string $output, string $type): void
    {
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            if (empty($line)) {
                continue;
            }

            $logEntry = [
                'timestamp' => time(),
                'worker_id' => $workerId,
                'type' => $type,
                'message' => $line,
                'level' => $this->detectLogLevel($line),
            ];

            if ($this->shouldIncludeOutput($logEntry)) {
                $this->outputBuffer[] = $logEntry;
            }
        }

        // Keep buffer size manageable
        if (count($this->outputBuffer) > 1000) {
            $this->outputBuffer = array_slice($this->outputBuffer, -1000);
        }
    }

    /**
     * Display streaming output
     */
    private function displayStreaming(string $format): void
    {
        switch ($format) {
            case 'json':
                $this->displayJsonStream();
                break;
            case 'table':
                $this->displayTableStream();
                break;
            default:
                $this->displayTextStream();
        }

        // Notify subscribers
        $this->notifySubscribers();
    }

    /**
     * Display as text stream
     */
    private function displayTextStream(): void
    {
        $this->clearScreen();
        echo "\033[1m=== Queue Worker Streaming Monitor ===\033[0m\n";
        echo "Time: " . date('Y-m-d H:i:s') . " | Press Ctrl+C to exit\n\n";

        // Display worker status summary
        $this->displayWorkerSummary();

        echo "\n\033[1m--- Recent Output ---\033[0m\n";

        // Display recent output (last 20 lines)
        $recentOutput = array_slice($this->outputBuffer, -20);

        foreach ($recentOutput as $entry) {
            $timestamp = date('H:i:s', $entry['timestamp']);
            $workerId = substr($entry['worker_id'], 0, 12);
            $level = $this->colorizeLevel($entry['level']);
            $message = $entry['message'];

            echo sprintf(
                "\033[90m[%s]\033[0m \033[94m%s\033[0m %s %s\n",
                $timestamp,
                $workerId,
                $level,
                $message
            );
        }
    }

    /**
     * Display as JSON stream
     */
    private function displayJsonStream(): void
    {
        $workers = $this->processManager->getStatus();
        $recentOutput = array_slice($this->outputBuffer, -10);

        $data = [
            'timestamp' => time(),
            'workers' => $workers,
            'recent_output' => $recentOutput,
            'output_count' => count($this->outputBuffer),
        ];

        echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * Display as table stream
     */
    private function displayTableStream(): void
    {
        $this->clearScreen();
        echo "\033[1m=== Queue Worker Status Table ===\033[0m\n";
        echo "Time: " . date('Y-m-d H:i:s') . "\n\n";

        $workers = $this->processManager->getStatus();

        if (empty($workers)) {
            echo "No workers running\n";
            return;
        }

        // Header
        printf(
            "%-15s %-10s %-8s %-10s %-8s %-6s %-8s %-20s\n",
            'Worker ID',
            'Queue',
            'Status',
            'Memory',
            'CPU',
            'Jobs',
            'Runtime',
            'Last Output'
        );
        echo str_repeat('-', 100) . "\n";

        // Worker rows
        foreach ($workers as $worker) {
            $lastOutput = $this->getLastOutputForWorker($worker['id']);

            printf(
                "%-15s %-10s %-8s %-10s %-8s %-6s %-8s %-20s\n",
                substr($worker['id'], 0, 15),
                $worker['queue'],
                $this->formatStatus($worker['status']),
                $this->formatBytes($worker['memory_usage']),
                sprintf('%.1f%%', $worker['cpu_usage']),
                $worker['jobs_processed'],
                $this->formatDuration($worker['runtime'] ?? 0),
                substr($lastOutput, 0, 20)
            );
        }
    }

    /**
     * Display worker summary
     */
    private function displayWorkerSummary(): void
    {
        $workers = $this->processManager->getStatus();
        $total = count($workers);
        $running = count(array_filter($workers, fn($w) => $w['status'] === 'running'));
        $totalJobs = array_sum(array_column($workers, 'jobs_processed'));
        $avgMemory = $total > 0 ? array_sum(array_column($workers, 'memory_usage')) / $total : 0;

        echo sprintf(
            "Workers: %d total, %d running | Jobs: %d processed | Avg Memory: %s\n",
            $total,
            $running,
            $totalJobs,
            $this->formatBytes($avgMemory)
        );
    }

    /**
     * Get last output for a specific worker
     */
    private function getLastOutputForWorker(string $workerId): string
    {
        $workerOutput = array_filter(
            $this->outputBuffer,
            fn($entry) => $entry['worker_id'] === $workerId
        );

        if (empty($workerOutput)) {
            return 'No output';
        }

        $last = end($workerOutput);
        return $last['message'];
    }

    /**
     * Detect log level from message content
     */
    private function detectLogLevel(string $message): string
    {
        $message = strtolower($message);

        if (strpos($message, 'error') !== false || strpos($message, 'exception') !== false) {
            return 'error';
        }
        if (strpos($message, 'warning') !== false || strpos($message, 'warn') !== false) {
            return 'warning';
        }
        if (strpos($message, 'debug') !== false) {
            return 'debug';
        }
        if (strpos($message, '[heartbeat]') !== false) {
            return 'heartbeat';
        }
        if (strpos($message, '[job_completed]') !== false) {
            return 'success';
        }

        return 'info';
    }

    /**
     * Check if output should be included based on filters
     */
    private function shouldIncludeOutput(array $logEntry): bool
    {
        // Worker ID filter
        if (isset($this->filters['worker_id'])) {
            if (strpos($logEntry['worker_id'], $this->filters['worker_id']) === false) {
                return false;
            }
        }

        // Log level filter
        if (isset($this->filters['level'])) {
            $allowedLevels = (array) $this->filters['level'];
            if (!in_array($logEntry['level'], $allowedLevels)) {
                return false;
            }
        }

        // Message content filter
        if (isset($this->filters['message'])) {
            if (strpos(strtolower($logEntry['message']), strtolower($this->filters['message'])) === false) {
                return false;
            }
        }

        // Hide heartbeat messages unless explicitly requested
        if ($logEntry['level'] === 'heartbeat' && !($this->filters['include_heartbeat'] ?? false)) {
            return false;
        }

        return true;
    }

    /**
     * Colorize log level for terminal output
     */
    private function colorizeLevel(string $level): string
    {
        return match ($level) {
            'error' => "\033[31m[ERROR]\033[0m",
            'warning' => "\033[33m[WARN]\033[0m",
            'success' => "\033[32m[SUCCESS]\033[0m",
            'debug' => "\033[90m[DEBUG]\033[0m",
            'heartbeat' => "\033[96m[HB]\033[0m",
            default => "\033[37m[INFO]\033[0m"
        };
    }

    /**
     * Format worker status with colors
     */
    private function formatStatus(string $status): string
    {
        return match ($status) {
            'running' => "\033[32m●\033[0m Run",
            'stopped' => "\033[31m●\033[0m Stop",
            default => "\033[33m●\033[0m {$status}"
        };
    }

    /**
     * Format bytes for display
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return sprintf('%.1fK', $bytes / 1024);
        }
        return sprintf('%.1fM', $bytes / 1024 / 1024);
    }

    /**
     * Format duration for display
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        if ($seconds < 3600) {
            return sprintf('%dm', floor($seconds / 60));
        }
        return sprintf('%dh%dm', floor($seconds / 3600), floor(($seconds % 3600) / 60));
    }

    /**
     * Clear screen for streaming display
     */
    private function clearScreen(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            system('cls');
        } else {
            system('clear');
        }
    }

    /**
     * Subscribe to streaming updates
     */
    public function subscribe(callable $callback, array $filters = []): string
    {
        $id = uniqid();
        $this->subscribers[$id] = [
            'callback' => $callback,
            'filters' => $filters,
        ];

        return $id;
    }

    /**
     * Unsubscribe from streaming updates
     */
    public function unsubscribe(string $id): bool
    {
        if (isset($this->subscribers[$id])) {
            unset($this->subscribers[$id]);
            return true;
        }

        return false;
    }

    /**
     * Notify all subscribers
     */
    private function notifySubscribers(): void
    {
        $data = [
            'workers' => $this->processManager->getStatus(),
            'recent_output' => array_slice($this->outputBuffer, -10),
            'timestamp' => time(),
        ];

        foreach ($this->subscribers as $subscriber) {
            try {
                call_user_func($subscriber['callback'], $data);
            } catch (\Exception $e) {
                $this->logger->error('Subscriber callback failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get output buffer (for API access)
     */
    public function getOutputBuffer(int $limit = 100): array
    {
        return array_slice($this->outputBuffer, -$limit);
    }

    /**
     * Clear output buffer
     */
    public function clearOutputBuffer(): void
    {
        $this->outputBuffer = [];
    }

    /**
     * Export output buffer to file
     */
    public function exportOutput(string $filename, string $format = 'json'): bool
    {
        try {
            $data = [
                'export_time' => date('Y-m-d H:i:s'),
                'worker_status' => $this->processManager->getStatus(),
                'output_buffer' => $this->outputBuffer,
            ];

            switch ($format) {
                case 'json':
                    file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
                    break;
                case 'csv':
                    $this->exportToCsv($filename, $this->outputBuffer);
                    break;
                default:
                    $this->exportToText($filename, $this->outputBuffer);
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to export output', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Export to CSV format
     */
    private function exportToCsv(string $filename, array $data): void
    {
        $file = fopen($filename, 'w');

        // CSV headers
        fputcsv($file, ['Timestamp', 'Worker ID', 'Type', 'Level', 'Message']);

        foreach ($data as $entry) {
            fputcsv($file, [
                date('Y-m-d H:i:s', $entry['timestamp']),
                $entry['worker_id'],
                $entry['type'],
                $entry['level'],
                $entry['message'],
            ]);
        }

        fclose($file);
    }

    /**
     * Export to text format
     */
    private function exportToText(string $filename, array $data): void
    {
        $content = "Queue Worker Output Export\n";
        $content .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $content .= str_repeat('=', 50) . "\n\n";

        foreach ($data as $entry) {
            $content .= sprintf(
                "[%s] %s (%s/%s): %s\n",
                date('Y-m-d H:i:s', $entry['timestamp']),
                substr($entry['worker_id'], 0, 12),
                $entry['type'],
                $entry['level'],
                $entry['message']
            );
        }

        file_put_contents($filename, $content);
    }
}
