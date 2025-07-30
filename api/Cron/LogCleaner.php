<?php

namespace Glueful\Cron;

use Glueful\Database\Connection;

class LogCleaner
{
    private array $stats = [
        'deleted_files' => 0,
        'deleted_db_logs' => 0,
        'bytes_freed' => 0,
        'errors' => []
    ];

    private Connection $connection;

    public function __construct()
    {
        $this->connection = new Connection();
    }

    public function cleanFileSystemLogs(int $retentionDays): void
    {
        $logDir = config('app.paths.logs');

        if (!is_dir($logDir)) {
            return;
        }

        $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
        $files = glob($logDir . '/*.log');

        foreach ($files as $file) {
            try {
                if (filemtime($file) < $cutoffTime) {
                    $size = filesize($file);
                    if (unlink($file)) {
                        $this->stats['deleted_files']++;
                        $this->stats['bytes_freed'] += $size;
                    }
                }
            } catch (\Exception $e) {
                $this->stats['errors'][] = "Failed to delete file {$file}: " . $e->getMessage();
            }
        }
    }

    public function cleanDatabaseLogs(int $retentionDays): void
    {
        try {
            // Clean app_logs table if it exists
            $cutoffDate = date('Y-m-d H:i:s', time() - ($retentionDays * 24 * 60 * 60));

            $affected = $this->connection->table('app_logs')
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            $this->stats['deleted_db_logs'] = $affected;
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean database logs: " . $e->getMessage();
        }
    }

    public function cleanAuditLogs(int $retentionDays): void
    {
        try {
            // Clean audit_logs table if it exists
            $cutoffDate = date('Y-m-d H:i:s', time() - ($retentionDays * 24 * 60 * 60));

            $affected = $this->connection->table('audit_logs')
                ->where('created_at', '<', $cutoffDate)
                ->delete();

            if ($affected) {
                $this->stats['deleted_db_logs'] += $affected;
            }
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean audit logs: " . $e->getMessage();
        }
    }

    public function logResults(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $message = sprintf(
            "[%s] Log cleanup completed:\n" .
            "- Log files deleted: %d\n" .
            "- Database log records deleted: %d\n" .
            "- Disk space freed: %s\n",
            $timestamp,
            $this->stats['deleted_files'],
            $this->stats['deleted_db_logs'],
            $this->formatBytes($this->stats['bytes_freed'])
        );

        if (!empty($this->stats['errors'])) {
            $message .= "Errors:\n- " . implode("\n- ", $this->stats['errors']) . "\n";
        }

        $logFile = dirname(__DIR__, 2) . '/storage/logs/log-cleanup.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logFile, $message . "\n", FILE_APPEND);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }

    public function handle(array $parameters = []): mixed
    {
        $retentionDays = $parameters['retention_days'] ?? 30;

        $this->cleanFileSystemLogs($retentionDays);
        $this->cleanDatabaseLogs($retentionDays);
        $this->cleanAuditLogs($retentionDays);
        $this->logResults();

        return $this->stats;
    }
}
