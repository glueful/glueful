<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Database\Migrations\MigrationManager;

/**
 * Database Migration System
 * 
 * Manages database schema version control:
 * - Executes pending migrations
 * - Tracks migration history
 * - Handles schema versioning
 * - Provides rollback capability
 * - Ensures data integrity
 * - Supports dry runs
 * - Enforces migration order
 * - Manages dependencies
 * 
 * @package Glueful\Console\Commands
 */
class MigrateCommand extends Command
{
    /** @var MigrationManager Database migration handler */
    private MigrationManager $migrationManager;

    /**
     * Initialize Migration Command
     * 
     * Sets up migration environment:
     * - Creates migration manager
     * - Validates migration path
     * - Checks database connection
     * - Prepares logging system
     * 
     * @throws \RuntimeException If initialization fails
     */
    public function __construct()
    {
        $this->migrationManager = new MigrationManager();
    }

    /**
     * Get Command Name
     * 
     * Returns command identifier:
     * - Used as `php glueful db:migrate`
     * - Follows naming standards
     * - Must be unique
     * 
     * @return string Command name
     */
    public function getName(): string
    {
        return 'db:migrate';
    }

    /**
     * Get Command Description
     * 
     * Provides command summary:
     * - Shows in command lists
     * - Single line description
     * - Explains primary purpose
     * 
     * @return string Brief description
     */
    public function getDescription(): string
    {
        return 'Run database migrations to update schema';
    }

    /**
     * Get Command Help
     * 
     * Details command usage:
     * - Shows syntax options
     * - Lists parameters
     * - Provides examples
     * - Explains behavior
     * 
     * @return string Detailed help text
     */
    public function getHelp(): string
    {
        return <<<HELP
    Usage:
      db:migrate [options]
    
    Description:
      Runs all pending database migrations in sequential order.
    
    Options:
      -h, --help       Display this help message
      --force          Skip confirmation for production environment
      --dry-run        Show which migrations would run without executing them
      --file           Run a specific migration file
    
    Examples:
      php glueful db:migrate
      php glueful db:migrate --force
      php glueful db:migrate --dry-run
      php glueful db:migrate --file CreateUsersTable.php
    HELP;
    }

    /**
     * Execute Migration Process
     * 
     * Handles migration workflow:
     * - Validates arguments
     * - Checks environment
     * - Processes migrations
     * - Reports results
     * - Handles errors
     * - Logs operations
     * 
     * @param array $args Command arguments
     * @throws \RuntimeException If migration fails
     * @return void
     */
    public function execute(array $args = []): void
    {
        $force = in_array('--force', $args);
        $dryRun = in_array('--dry-run', $args);
        $fileIndex = array_search('--file', $args);
        $specificFile = $fileIndex !== false ? ($args[$fileIndex + 1] ?? null) : null;
        
        if ($dryRun) {
            $this->info("\nPending Migrations:");
            $this->info("------------------");
            $pendingMigrations = $this->migrationManager->getPendingMigrations();
            if (empty($pendingMigrations)) {
                $this->info("No pending migrations.");
                return;
            }
            foreach ($pendingMigrations as $migration) {
                $this->info(" • " . basename($migration));
            }
            return;
        }

        if (!$force && $this->isProduction()) {
            $this->error("You're in production! Use --force to proceed.");
            return;
        }

        try {
            if ($specificFile) {
                $fullPath = __DIR__ . '/../../../database/migrations/' . $specificFile;
                if (!file_exists($fullPath)) {
                    $this->error("Migration file not found: $specificFile");
                    return;
                }
                
                $result = $this->migrationManager->migrate($fullPath);
                $this->displayMigrationResult($result, true);
            } else {
                $result = $this->migrationManager->migrate();
                $this->displayMigrationResult($result);
            }
        } catch (\Exception $e) {
            error_log("Migration failed: " . $e);
            $this->error("Migration failed: " . $e->getMessage());
        }
    }

    /**
     * Display Migration Results
     * 
     * Formats operation output:
     * - Lists successful migrations
     * - Shows failed operations
     * - Provides statistics
     * - Indicates warnings
     * - Reports errors
     * 
     * @param array $result Operation results
     * @param bool $isSingle Single file mode
     * @return void
     */
    private function displayMigrationResult(array $result, bool $isSingle = false): void
    {
        if (empty($result['applied']) && empty($result['failed'])) {
            $this->info("Nothing to migrate.");
            return;
        }

        if (!empty($result['applied'])) {
            $this->info("\nSuccessfully applied:");
            foreach ($result['applied'] as $migration) {
                $this->info(" ✓ " . basename($migration));
            }
        }

        if (!empty($result['failed'])) {
            $this->error("\nFailed migrations:");
            foreach ($result['failed'] as $migration) {
                $this->error(" ✗ " . basename($migration));
            }
        }

        $total = count($result['applied']) + count($result['failed']);
        $this->info("\nMigration complete: {$total} " . ($isSingle ? 'file' : 'files') . " processed");
    }

}
