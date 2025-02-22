<?php

namespace Glueful\App\Console\Commands;

use Glueful\App\Console\Command;
use Glueful\App\Migrations\MigrationManager;

/**
 * Database Migration Command
 * 
 * Command-line interface for managing database migrations.
 * Provides functionality for:
 * - Running pending migrations
 * - Executing specific migrations
 * - Previewing pending changes
 * - Production safety controls
 * - Migration status reporting
 * 
 * Examples:
 * ```bash
 * # Run all pending migrations
 * php glueful db:migrate
 * 
 * # Run specific migration
 * php glueful db:migrate --file CreateUsersTable.php
 * 
 * # Preview changes
 * php glueful db:migrate --dry-run
 * 
 * # Force run in production
 * php glueful db:migrate --force
 * ```
 * 
 * Safety Features:
 * - Production environment detection
 * - Dry run capability
 * - Transaction safety
 * - Detailed error reporting
 */
class MigrateCommand extends Command
{
    /** @var MigrationManager Database migration manager instance */
    private MigrationManager $migrationManager;

    /**
     * Initialize migration command
     * 
     * Sets up migration manager instance.
     */
    public function __construct()
    {
        $this->migrationManager = new MigrationManager();
    }

    /**
     * Get command identifier
     * 
     * @return string Command name used in CLI
     */
    public function getName(): string
    {
        return 'db:migrate';
    }

    /**
     * Get command description
     * 
     * @return string Short description for command listing
     */
    public function getDescription(): string
    {
        return 'Run database migrations to update schema';
    }

    /**
     * Get detailed usage instructions
     * 
     * Returns formatted help including:
     * - Command syntax
     * - Available options
     * - Usage examples
     * - Operation descriptions
     * 
     * @return string Multi-line help text with examples
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
     * Execute migration command
     * 
     * Process flow:
     * 1. Parse command arguments
     * 2. Handle dry run requests
     * 3. Enforce production safety
     * 4. Execute migrations
     * 5. Display results
     * 
     * Supported options:
     * --force   Override production safety check
     * --dry-run Preview pending migrations
     * --file    Run specific migration file
     * 
     * @param array $args Command line arguments
     * @throws \RuntimeException If migration fails
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
            $this->error("Migration failed: " . $e->getMessage());
        }
    }

    /**
     * Display migration operation results
     * 
     * Formats and displays:
     * - Successfully applied migrations
     * - Failed migrations
     * - Operation summary
     * 
     * @param array{
     *     applied: array<string>,
     *     failed: array<string>
     * } $result Migration operation results
     * @param bool $isSingle Whether this was a single file operation
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
