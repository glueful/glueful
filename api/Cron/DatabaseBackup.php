<?php

namespace Glueful\Cron;

use Glueful\Database\Connection;
use Glueful\Helpers\ConfigManager;

class DatabaseBackup
{
    private array $stats = [
        'backup_created' => false,
        'backup_file' => '',
        'backup_size' => 0,
        'old_backups_deleted' => 0,
        'errors' => []
    ];

    private Connection $connection;
    private array $config;

    public function __construct()
    {
        $this->connection = new Connection();
        $this->config = ConfigManager::get('database');
    }

    public function createBackup(): void
    {
        try {
            $backupDir = config('app.paths.backups');

            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $timestamp = date('Y-m-d_H-i-s');
            $filename = "backup_{$timestamp}.sql";
            $backupFile = $backupDir . '/' . $filename;

            $driver = $this->config['driver'] ?? 'mysql';

            switch ($driver) {
                case 'mysql':
                    $this->createMySQLBackup($backupFile);
                    break;
                case 'pgsql':
                    $this->createPostgreSQLBackup($backupFile);
                    break;
                case 'sqlite':
                    $this->createSQLiteBackup($backupFile);
                    break;
                default:
                    throw new \Exception("Unsupported database driver: {$driver}");
            }

            if (file_exists($backupFile)) {
                $this->stats['backup_created'] = true;
                $this->stats['backup_file'] = $filename;
                $this->stats['backup_size'] = filesize($backupFile);
            }
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to create backup: " . $e->getMessage();
        }
    }

    private function createMySQLBackup(string $backupFile): void
    {
        $host = $this->config['host'] ?? 'localhost';
        $port = $this->config['port'] ?? 3306;
        $database = $this->config['database'];
        $username = $this->config['username'];
        $password = $this->config['password'];

        $command = sprintf(
            'mysqldump --host=%s --port=%d --user=%s --password=%s ' .
            '--single-transaction --routines --triggers %s > %s 2>&1',
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($database),
            escapeshellarg($backupFile)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("mysqldump failed: " . implode("\n", $output));
        }
    }

    private function createPostgreSQLBackup(string $backupFile): void
    {
        $host = $this->config['host'] ?? 'localhost';
        $port = $this->config['port'] ?? 5432;
        $database = $this->config['database'];
        $username = $this->config['username'];

        // Set PGPASSWORD environment variable
        $env = ['PGPASSWORD' => $this->config['password']];

        $command = sprintf(
            'pg_dump --host=%s --port=%d --username=%s --no-password --format=plain --file=%s %s 2>&1',
            escapeshellarg($host),
            $port,
            escapeshellarg($username),
            escapeshellarg($backupFile),
            escapeshellarg($database)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception("pg_dump failed: " . implode("\n", $output));
        }
    }

    private function createSQLiteBackup(string $backupFile): void
    {
        $databaseFile = $this->config['database'];

        if (!file_exists($databaseFile)) {
            throw new \Exception("SQLite database file not found: {$databaseFile}");
        }

        if (!copy($databaseFile, $backupFile)) {
            throw new \Exception("Failed to copy SQLite database file");
        }
    }

    public function cleanOldBackups(int $retentionDays): void
    {
        try {
            $backupDir = dirname(__DIR__, 2) . '/storage/backups';

            if (!is_dir($backupDir)) {
                return;
            }

            $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
            $files = glob($backupDir . '/backup_*.sql');

            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    if (unlink($file)) {
                        $this->stats['old_backups_deleted']++;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean old backups: " . $e->getMessage();
        }
    }

    public function logResults(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $message = sprintf(
            "[%s] Database backup completed:\n" .
            "- Backup created: %s\n" .
            "- Backup file: %s\n" .
            "- Backup size: %s\n" .
            "- Old backups deleted: %d\n",
            $timestamp,
            $this->stats['backup_created'] ? 'Yes' : 'No',
            $this->stats['backup_file'],
            $this->formatBytes($this->stats['backup_size']),
            $this->stats['old_backups_deleted']
        );

        if (!empty($this->stats['errors'])) {
            $message .= "Errors:\n- " . implode("\n- ", $this->stats['errors']) . "\n";
        }

        $logFile = dirname(__DIR__, 2) . '/storage/logs/database-backup.log';
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
        $retentionDays = $parameters['retention_days'] ?? 7;

        $this->createBackup();
        $this->cleanOldBackups($retentionDays);
        $this->logResults();

        return $this->stats;
    }
}
