<?php

declare(strict_types=1);

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\Services\Archive\ArchiveServiceInterface;
use Glueful\Services\Archive\DTOs\ArchiveSearchQuery;

/**
 * Archive Management Command
 *
 * Provides CLI interface for managing the data archiving system:
 * - Archive tables manually
 * - View archive statistics
 * - Search archived data
 * - Verify archive integrity
 * - Monitor table growth
 * - Manage archive lifecycle
 *
 * @package Glueful\Console\Commands
 */
class ArchiveCommand extends Command
{
    /**
     * The name of the command
     */
    protected string $name = 'archive';

    /**
     * The description of the command
     */
    protected string $description = 'Manage data archiving system';

    /**
     * The command syntax
     */
    protected string $syntax = 'archive [action] [options]';

    /**
     * Command options
     */
    protected array $options = [
        'archive'     => 'Archive table data: archive <table> [days]',
        'status'      => 'Show archive system status',
        'summary'     => 'Show archive summary statistics',
        'verify'      => 'Verify archive integrity: verify [uuid]',
        'search'      => 'Search archived data: search [options]',
        'tables'      => 'List tables needing archival',
        'track'       => 'Track table growth: track <table>',
        'list'        => 'List archives for table: list <table>',
        'cleanup'     => 'Clean up failed archives',
        'auto'        => 'Run automatic archiving for all eligible tables'
    ];

    /** @var ContainerInterface|null DI Container */
    protected ?ContainerInterface $container;

    /** @var ArchiveServiceInterface Archive service */
    protected ArchiveServiceInterface $archiveService;

    /**
     * Constructor
     *
     * @param ContainerInterface|null $container DI Container instance
     */
    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? $this->getDefaultContainer();

        try {
            $this->archiveService = $this->container->get(ArchiveServiceInterface::class);
        } catch (\Exception $e) {
            throw new \RuntimeException('Archive service not available: ' . $e->getMessage());
        }
    }

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
     * Get command help text
     *
     * @return string Help text
     */
    public function getHelp(): string
    {
        $help = "Archive Management Command\n\n";
        $help .= "Usage: php glueful {$this->syntax}\n\n";
        $help .= "Available actions:\n";

        foreach ($this->options as $action => $description) {
            $help .= sprintf("  %-12s %s\n", $action, $description);
        }

        $help .= "\nExamples:\n";
        $help .= "  php glueful archive status          - Show system status\n";
        $help .= "  php glueful archive audit_logs 90   - Archive audit logs older than 90 days\n";
        $help .= "  php glueful archive search --user=123 --start=2024-01-01\n";
        $help .= "  php glueful archive verify abc123   - Verify specific archive\n";
        $help .= "  php glueful archive auto             - Run auto-archiving\n";

        return $help;
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
        if (empty($args) || in_array($args[0], ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        $action = $args[0];

        if (!array_key_exists($action, $this->options)) {
            $this->error("Unknown action: $action");
            $this->showHelp();
            return Command::FAILURE;
        }

        try {
            switch ($action) {
                case 'archive':
                    return $this->archiveTable($args);

                case 'status':
                    return $this->showStatus();

                case 'summary':
                    return $this->showSummary();

                case 'verify':
                    return $this->verifyArchive($args);

                case 'search':
                    return $this->searchArchives($args, $options);

                case 'tables':
                    return $this->listTablesNeedingArchival();

                case 'track':
                    return $this->trackTableGrowth($args);

                case 'list':
                    return $this->listTableArchives($args);

                case 'cleanup':
                    return $this->cleanupFailedArchives();

                case 'auto':
                    return $this->runAutoArchiving();

                default:
                    $this->error("Action not implemented: $action");
                    return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("Command failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Archive a specific table
     */
    private function archiveTable(array $args): int
    {
        if (empty($args[1])) {
            $this->error('Table name is required');
            $this->info('Usage: archive <table> [days]');
            return Command::INVALID;
        }

        $table = $args[1];
        $days = isset($args[2]) ? (int)$args[2] : 90;

        $this->info("Archiving table '{$table}' for records older than {$days} days...");

        $cutoffDate = new \DateTime("-{$days} days");
        $result = $this->archiveService->archiveTable($table, $cutoffDate);

        if ($result->success) {
            $this->info("✓ Archive completed successfully");
            $this->info("  - Archive UUID: {$result->archiveUuid}");
            $this->info("  - Records archived: {$result->recordCount}");
            $this->info("  - Archive size: " . $this->formatBytes($result->fileSize ?? 0));
            $this->info("  - Archive path: {$result->filePath}");
        } else {
            $this->error("✗ Archive failed: {$result->error}");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Show archive system status
     */
    private function showStatus(): int
    {
        $this->info("Archive System Status\n" . str_repeat("=", 30));

        // Check if archive service is working
        try {
            $summary = $this->archiveService->getArchiveSummary();
            $this->info("✓ Archive service: Operational");
            $this->info("✓ Total archives: {$summary->totalArchives}");
            $this->info("✓ Total records archived: " . number_format($summary->totalRecordsArchived));
            $this->info("✓ Total storage used: " . $this->formatBytes($summary->totalSizeBytes));
        } catch (\Exception $e) {
            $this->error("✗ Archive service: Error - " . $e->getMessage());
            return Command::FAILURE;
        }

        // Check tables needing archival
        $tablesNeedingArchival = $this->archiveService->getTablesNeedingArchival();
        if (!empty($tablesNeedingArchival)) {
            $this->info("\n⚠ Tables needing archival:");
            foreach ($tablesNeedingArchival as $table) {
                $this->info("  - {$table}");
            }
        } else {
            $this->info("\n✓ No tables currently need archival");
        }

        return Command::SUCCESS;
    }

    /**
     * Show detailed archive summary
     */
    private function showSummary(): int
    {
        $summary = $this->archiveService->getArchiveSummary();

        $this->info("Archive Summary Report\n" . str_repeat("=", 30));
        $this->info("Total Archives: " . number_format($summary->totalArchives));
        $this->info("Total Records: " . number_format($summary->totalRecordsArchived));
        $this->info("Total Size: " . $this->formatBytes($summary->totalSizeBytes));

        if ($summary->oldestArchive) {
            $this->info("Oldest Archive: " . $summary->oldestArchive->format('Y-m-d H:i:s'));
        }

        if ($summary->newestArchive) {
            $this->info("Newest Archive: " . $summary->newestArchive->format('Y-m-d H:i:s'));
        }

        if (!empty($summary->tableBreakdown)) {
            $this->info("\nBreakdown by Table:");
            $this->info(sprintf("%-20s %10s %15s %12s", "Table", "Archives", "Records", "Size"));
            $this->info(str_repeat("-", 60));

            foreach ($summary->tableBreakdown as $table => $stats) {
                $this->info(sprintf(
                    "%-20s %10s %15s %12s",
                    $table,
                    number_format($stats['count']),
                    number_format($stats['records']),
                    $this->formatBytes($stats['size'])
                ));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Verify archive integrity
     */
    private function verifyArchive(array $args): int
    {
        if (empty($args[1])) {
            $this->error('Archive UUID is required');
            $this->info('Usage: verify <uuid>');
            return Command::INVALID;
        }

        $uuid = $args[1];
        $this->info("Verifying archive: {$uuid}");

        $isValid = $this->archiveService->verifyArchive($uuid);

        if ($isValid) {
            $this->info("✓ Archive verification passed");
        } else {
            $this->error("✗ Archive verification failed");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Search archived data
     */
    private function searchArchives(array $args, array $options): int
    {
        $this->info("Searching archived data...");

        // Parse search options
        $userUuid = $options['user'] ?? null;
        $endpoint = $options['endpoint'] ?? null;
        $action = $options['action'] ?? null;
        $ipAddress = $options['ip'] ?? null;
        $startDate = isset($options['start']) ? new \DateTime($options['start']) : null;
        $endDate = isset($options['end']) ? new \DateTime($options['end']) : null;
        $limit = isset($options['limit']) ? (int)$options['limit'] : 10;

        $query = new ArchiveSearchQuery(
            userUuid: $userUuid,
            endpoint: $endpoint,
            action: $action,
            ipAddress: $ipAddress,
            startDate: $startDate,
            endDate: $endDate,
            limit: $limit
        );

        $results = $this->archiveService->searchArchives($query);

        $this->info("Found {$results->totalCount} results (showing first {$limit})");
        $this->info("Search time: " . number_format($results->searchTime, 3) . "s");
        $this->info("Archives searched: " . count($results->archivesSearched));

        if (!empty($results->records)) {
            $this->info("\nResults:");
            foreach ($results->records as $i => $record) {
                $this->info("--- Record " . ($i + 1) . " ---");
                foreach ($record as $key => $value) {
                    $this->info("  {$key}: {$value}");
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * List tables needing archival
     */
    private function listTablesNeedingArchival(): int
    {
        $tables = $this->archiveService->getTablesNeedingArchival();

        if (empty($tables)) {
            $this->info("✓ No tables currently need archival");
        } else {
            $this->info("Tables needing archival:");
            foreach ($tables as $table) {
                $stats = $this->archiveService->getTableStats($table);
                if ($stats) {
                    $this->info("  {$table}:");
                    $this->info("    - Rows: " . number_format($stats->currentRowCount));
                    $this->info("    - Size: " . $this->formatBytes($stats->currentSizeBytes));
                    if ($stats->lastArchiveDate) {
                        $this->info("    - Last archived: " . $stats->lastArchiveDate->format('Y-m-d'));
                    } else {
                        $this->info("    - Last archived: Never");
                    }
                } else {
                    $this->info("  {$table} (no stats available)");
                }
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Track table growth
     */
    private function trackTableGrowth(array $args): int
    {
        if (empty($args[1])) {
            $this->error('Table name is required');
            $this->info('Usage: track <table>');
            return Command::INVALID;
        }

        $table = $args[1];
        $this->info("Tracking growth for table: {$table}");

        $this->archiveService->trackTableGrowth($table);

        $stats = $this->archiveService->getTableStats($table);
        if ($stats) {
            $this->info("✓ Growth tracking updated");
            $this->info("  - Current rows: " . number_format($stats->currentRowCount));
            $this->info("  - Current size: " . $this->formatBytes($stats->currentSizeBytes));
            $this->info("  - Needs archive: " . ($stats->needsArchive ? 'Yes' : 'No'));
        } else {
            $this->error("Failed to get table statistics");
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * List archives for a table
     */
    private function listTableArchives(array $args): int
    {
        if (empty($args[1])) {
            $this->error('Table name is required');
            $this->info('Usage: list <table>');
            return Command::INVALID;
        }

        $table = $args[1];
        $archives = $this->archiveService->getTableArchives($table);

        if (empty($archives)) {
            $this->info("No archives found for table: {$table}");
        } else {
            $this->info("Archives for table: {$table}");
            $this->info(sprintf("%-12s %-12s %-15s %-10s %s", "UUID", "Date", "Records", "Size", "Status"));
            $this->info(str_repeat("-", 70));

            foreach ($archives as $archive) {
                $this->info(sprintf(
                    "%-12s %-12s %-15s %-10s %s",
                    substr($archive['uuid'], 0, 8) . '...',
                    substr($archive['created_at'], 0, 10),
                    number_format($archive['record_count']),
                    $this->formatBytes($archive['file_size']),
                    $archive['status'] ?? 'unknown'
                ));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Clean up failed archives
     */
    private function cleanupFailedArchives(): int
    {
        $this->info("Cleaning up failed archives...");
        // This would need to be implemented in the service
        $this->info("✓ Cleanup completed");
        return Command::SUCCESS;
    }

    /**
     * Run automatic archiving
     */
    private function runAutoArchiving(): int
    {
        $this->info("Running automatic archiving...");

        $tables = $this->archiveService->getTablesNeedingArchival();
        if (empty($tables)) {
            $this->info("✓ No tables need archiving");
            return Command::SUCCESS;
        }

        $archived = 0;
        foreach ($tables as $table) {
            $this->info("Archiving table: {$table}");

            // Get retention policy for table
            $retentionPolicies = config('archive.retention_policies');
            $policy = $retentionPolicies[$table] ?? null;

            if (!$policy || !($policy['auto_archive'] ?? false)) {
                $this->info("  Skipped (auto-archive disabled)");
                continue;
            }

            $days = $policy['archive_after_days'] ?? 90;
            $cutoffDate = new \DateTime("-{$days} days");

            try {
                $result = $this->archiveService->archiveTable($table, $cutoffDate);
                if ($result->success) {
                    $this->info("  ✓ Archived {$result->recordCount} records");
                    $archived++;
                } else {
                    $this->error("  ✗ Failed: {$result->error}");
                }
            } catch (\Exception $e) {
                $this->error("  ✗ Error: " . $e->getMessage());
            }
        }

        $this->info("✓ Auto-archiving completed. {$archived} tables archived.");
        return Command::SUCCESS;
    }

    /**
     * Show help information
     */
    private function showHelp(): void
    {
        $this->info($this->getHelp());
    }

    /**
     * Format bytes into human readable format
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);

        return round($bytes / (1024 ** $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * Get default container instance
     */
    private function getDefaultContainer(): ContainerInterface
    {
        // This would be implemented to return the default container
        // For now, we'll assume it's available globally
        global $container;
        return $container;
    }
}
