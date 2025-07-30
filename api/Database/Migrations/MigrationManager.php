<?php

declare(strict_types=1);

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Connection;
use Glueful\Extensions\ExtensionManager;
use Glueful\Exceptions\DatabaseException;
use Glueful\Exceptions\BusinessLogicException;
use Glueful\Services\FileFinder;

/**
 * Database Migration Manager
 *
 * Manages database schema migrations including:
 * - Migration tracking using version table
 * - Forward and rollback migrations
 * - Batch management for grouped migrations
 * - Transaction handling for safe execution
 * - Checksum verification for file integrity
 * - Migration history tracking
 *
 * Each migration is executed within a transaction and tracked in the
 * migrations table. Supports rollback operations by batch number
 * and maintains migration order.
 *
 * Usage:
 * ```php
 * $manager = new MigrationManager();
 *
 * // Run pending migrations
 * $result = $manager->migrate();
 *
 * // Run specific migration
 * $result = $manager->migrate('/path/to/migration.php');
 *
 * // Rollback last batch
 * $result = $manager->rollback();
 * ```
 */
class MigrationManager
{
    /** @var SchemaBuilderInterface Database schema builder for table operations */
    private SchemaBuilderInterface $schema;

    /** @var Connection Database connection for fluent query operations */
    private Connection $db;

    /** @var string Directory containing migration files */
    private string $migrationsPath;

    /** @var ExtensionManager Extensions manager for checking enabled extensions */
    private ExtensionManager $extensionsManager;

    /** @var FileFinder File finder service for migration discovery */
    private FileFinder $fileFinder;

    /** @var string Name of migrations tracking table */
    private const VERSION_TABLE = 'migrations';

    /**
     * Initialize migration manager
     *
     * Sets up schema manager and ensures version table exists.
     *
     * @param string|null $migrationsPath Custom path to migrations directory
     * @param ExtensionManager|null $extensionsManager Extensions manager instance
     * @param FileFinder|null $fileFinder File finder service instance
     * @throws \Glueful\Exceptions\DatabaseException If database connection fails
     */
    public function __construct(
        ?string $migrationsPath = null,
        ?ExtensionManager $extensionsManager = null,
        ?FileFinder $fileFinder = null
    ) {
        $connection = new Connection();
        $this->db = $connection;
        $this->schema = $connection->getSchemaBuilder();

        $this->migrationsPath = $migrationsPath ?? config(('app.paths.migrations'));
        $this->extensionsManager = $extensionsManager ?? container()->get(ExtensionManager::class);
        $this->fileFinder = $fileFinder ?? container()->get(FileFinder::class);
        // echo $this->migrationsPath;
        // exit;
        $this->ensureVersionTable();
    }

    /**
     * Create migrations tracking table
     *
     * Ensures migrations table exists with required structure:
     * - id: Auto-incrementing primary key
     * - migration: Migration filename (unique)
     * - batch: Batch number for grouped rollbacks
     * - applied_at: Timestamp of execution
     * - checksum: File hash for integrity check
     * - description: Migration description
     * - extension: Extension name (NULL for core migrations)
     */
    private function ensureVersionTable(): void
    {
        if (!$this->schema->hasTable(self::VERSION_TABLE)) {
            $table = $this->schema->table(self::VERSION_TABLE);

            // Add columns
            $table->id();
            $table->string('migration', 255);
            $table->integer('batch');
            $table->timestamp('applied_at')->default('CURRENT_TIMESTAMP');
            $table->string('checksum', 64);
            $table->text('description')->nullable();
            $table->string('extension', 100)->nullable();

            // Add unique constraint
            $table->unique('migration');

            // Create the table
            $table->create()->execute();
        }
    }

    /**
     * Get pending migrations
     *
     * Returns list of migration files that haven't been executed:
     * - Scans migrations directory for .php files
     * - Scans ENABLED extensions migration directories only
     * - Compares against applied migrations
     * - Returns array of pending migration paths
     *
     * @return array<string> List of pending migration file paths
     */
    public function getPendingMigrations(): array
    {
        $applied = $this->getAppliedMigrations();

        // Get migrations from main directory using FileFinder
        $files = [];
        $mainMigrations = $this->fileFinder->findMigrations($this->migrationsPath);
        foreach ($mainMigrations as $file) {
            $files[] = $file->getPathname();
        }

        // Get migrations from enabled extensions only
        $enabledExtensions = $this->extensionsManager->listEnabled();

        foreach ($enabledExtensions as $extensionName) {
            $extensionPath = $this->extensionsManager->getExtensionPath($extensionName);
            if ($extensionPath) {
                $migrationDir = $extensionPath . '/migrations';
                $extensionMigrations = $this->fileFinder->findMigrations($migrationDir);
                foreach ($extensionMigrations as $file) {
                    $files[] = $file->getPathname();
                }
            }
        }

        $pendingFiles = array_filter($files, fn($file) => !in_array(basename($file), $applied));

        // Sort files by filename to ensure proper execution order
        usort($pendingFiles, fn($a, $b) => basename($a) <=> basename($b));

        return $pendingFiles;
    }

    /**
     * Get list of applied migrations
     *
     * @return array<string> List of applied migration filenames
     */
    private function getAppliedMigrations(): array
    {
        $result = $this->db
        ->table(self::VERSION_TABLE)
        ->select(['migration'])
        ->get();

        return array_column($result, 'migration');
    }

    /**
     * Get list of applied migrations (public method)
     *
     * @return array<string> List of applied migration filenames
     */
    public function getAppliedMigrationsList(): array
    {
        return $this->getAppliedMigrations();
    }

    /**
     * Get both pending and applied migrations efficiently
     *
     * @return array{
     *     pending: array<string>,
     *     applied: array<string>
     * } Migration status
     */
    public function getMigrationStatus(): array
    {
        // Get applied migrations once
        $applied = $this->getAppliedMigrations();

        // Get all migration files
        $files = [];
        $mainMigrations = $this->fileFinder->findMigrations($this->migrationsPath);
        foreach ($mainMigrations as $file) {
            $files[] = $file->getPathname();
        }

        // Get migrations from enabled extensions only
        $enabledExtensions = $this->extensionsManager->listEnabled();
        foreach ($enabledExtensions as $extensionName) {
            $extensionPath = $this->extensionsManager->getExtensionPath($extensionName);
            if ($extensionPath) {
                $migrationDir = $extensionPath . '/migrations';
                $extensionMigrations = $this->fileFinder->findMigrations($migrationDir);
                foreach ($extensionMigrations as $file) {
                    $files[] = $file->getPathname();
                }
            }
        }

        // Filter pending migrations
        $pendingFiles = array_filter($files, fn($file) => !in_array(basename($file), $applied));

        // Sort files by filename to ensure proper execution order
        usort($pendingFiles, fn($a, $b) => basename($a) <=> basename($b));

        return [
            'pending' => $pendingFiles,
            'applied' => $applied
        ];
    }

    /**
     * Run migrations
     *
     * Executes pending migrations in order. Can run either:
     * - All pending migrations
     * - Specific migration file
     * - Provided list of pending migrations (to avoid duplicate queries)
     *
     * @param string|array|null $specificFileOrPendingMigrations Optional specific migration file or array of pending
     *                                                            migrations
     * @return array{
     *     applied: array<string>,
     *     failed: array<string>
     * } Migration results
     */
    public function migrate($specificFileOrPendingMigrations = null): array
    {
        $results = ['applied' => [], 'failed' => []];
        // Handle specific file migration
        if (is_string($specificFileOrPendingMigrations)) {
            $batch = $this->getNextBatchNumber();
            $status = $this->runMigration($specificFileOrPendingMigrations, $batch);
            if ($status['success']) {
                $results['applied'][] = $status['file'];
            } else {
                $results['failed'][] = $status['file'];
            }
            return $results;
        }

        // Handle provided pending migrations array or get pending migrations
        if (is_array($specificFileOrPendingMigrations)) {
            $pendingMigrations = $specificFileOrPendingMigrations;
        } else {
            $pendingMigrations = $this->getPendingMigrations();
        }

        // If no pending migrations, return early
        if (empty($pendingMigrations)) {
            return $results;
        }

        // For batch migration, get the batch number once and reuse it
        $batch = $this->getNextBatchNumber();

        foreach ($pendingMigrations as $file) {
            $status = $this->runMigration($file, $batch);
            if ($status['success']) {
                $results['applied'][] = $status['file'];
            } else {
                $results['failed'][] = $status['file'];
            }
        }

        return $results;
    }

    /**
     * Execute single migration
     *
     * Runs a specific migration file:
     * 1. Loads migration class
     * 2. Verifies interface implementation
     * 3. Executes migration within transaction
     * 4. Records successful execution with extension tracking
     *
     * @param string $file Migration file path
     * @param int|null $batch Optional batch number
     * @return array{
     *     success: bool,
     *     file: string,
     *     error?: string
     * } Migration result
     */
    private function runMigration(string $file, ?int $batch = null): array
    {
        require_once $file;

        $className = pathinfo($file, PATHINFO_FILENAME);
        $className = preg_replace('/^\d+_/', '', $className); // Removes any leading digits and underscore

        // Try to determine if the file contains a namespaced class
        $fileContent = file_get_contents($file);
        $namespace = '';

        if (preg_match('/namespace\s+([^;]+);/i', $fileContent, $matches)) {
            $namespace = $matches[1] . '\\';
        }

        $fullClassName = $namespace . $className;

        if (!class_exists($fullClassName)) {
            // Fall back to non-namespaced class if namespace detection failed
            if (!class_exists($className)) {
                throw DatabaseException::queryFailed(
                    'MIGRATION_ERROR',
                    "Migration class $className not found in $file"
                );
            }
            $fullClassName = $className;
        }

        $migration = new $fullClassName();
        if (!$migration instanceof MigrationInterface) {
            throw BusinessLogicException::operationNotAllowed(
                'migration_validation',
                "Migration $fullClassName must implement MigrationInterface"
            );
        }

        $filename = basename($file);
        $checksum = hash_file('sha256', $file);

        // Determine if this is an extension migration
        $extensionName = null;
        $enabledExtensions = $this->extensionsManager->listEnabled();

        foreach ($enabledExtensions as $extension) {
            $extensionPath = $this->extensionsManager->getExtensionPath($extension);
            if ($extensionPath && strpos($file, $extensionPath) === 0) {
                $extensionName = $extension;
                break;
            }
        }

        try {
            // Run the migration schema operations - these will execute immediately
            $migration->up($this->schema);

            // Insert migration record after schema operations complete
            $this->db
                ->table(self::VERSION_TABLE)
                ->insert([
                    'migration' => $filename,
                    'batch' => $batch,
                    'checksum' => $checksum,
                    'description' => $migration->getDescription(),
                    'extension' => $extensionName
                ]);

            return ['success' => true, 'file' => $filename];
        } catch (\Exception $e) {
            error_log("Migration failed: " . $e->getMessage());
            return ['success' => false, 'file' => $filename, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get next batch number
     *
     * @return int Next sequential batch number
     */
    private function getNextBatchNumber(): int
    {
        $result = $this->db
        ->table(self::VERSION_TABLE)
        ->selectRaw("MAX(batch) AS max_batch")
        ->get();

        return (int)($result[0]['max_batch'] ?? 0) + 1;
    }

    /**
     * Rollback migrations
     *
     * Reverts most recent migrations:
     * - Rolls back by batch
     * - Maintains order within batch
     * - Removes from version history
     *
     * @param int $steps Number of migrations to roll back
     * @return array{
     *     reverted: array<string>,
     *     failed: array<string>
     * } Rollback results
     */
    public function rollback(int $steps = 1): array
    {
        $results = ['reverted' => [], 'failed' => []];
        $migrations = $this->getMigrationsToRollback($steps);

        foreach (array_reverse($migrations) as $migration) {
            $status = $this->rollbackMigration($migration);
            if ($status['success']) {
                $results['reverted'][] = $status['file'];
            } else {
                $results['failed'][] = $status['file'];
            }
        }

        return $results;
    }

    /**
     * Get migrations for rollback
     *
     * Returns list of migrations to roll back based on:
     * - Most recent batch first
     * - Specified number of steps
     *
     * @param int $steps Number of migrations to return
     * @return array<string> List of migration filenames
     */
    private function getMigrationsToRollback(int $steps): array
    {
        // Use schema manager for getting migrations to rollback

        $result = $this->db
        ->table(self::VERSION_TABLE)
        ->select(['migration'])
        ->orderBy('batch', 'DESC')
        ->orderBy('id', 'DESC')
        ->limit($steps)
        ->get();

        return array_column($result, 'migration');
    }

    /**
     * Revert single migration
     *
     * Rolls back a specific migration:
     * 1. Loads migration class
     * 2. Executes down() method
     * 3. Removes from version history
     *
     * @param string $filename Migration filename
     * @return array{
     *     success: bool,
     *     file: string,
     *     error?: string
     * } Rollback result
     */
    private function rollbackMigration(string $filename): array
    {
        // First check in main migrations directory
        $file = $this->migrationsPath . '/' . $filename;

        // If not found in main directory, check in enabled extension directories
        if (!file_exists($file)) {
            $found = false;
            $enabledExtensions = $this->extensionsManager->listEnabled();

            foreach ($enabledExtensions as $extensionName) {
                $extensionPath = $this->extensionsManager->getExtensionPath($extensionName);
                if ($extensionPath) {
                    $migrationDir = $extensionPath . '/migrations';
                    $extensionFile = $migrationDir . '/' . $filename;

                    // Use FileFinder to check if migration directory exists and file exists
                    $extensionMigrations = $this->fileFinder->findMigrations($migrationDir);
                    $matchingFile = null;
                    foreach ($extensionMigrations as $migFile) {
                        if ($migFile->getFilename() === $filename) {
                            $matchingFile = $migFile;
                            break;
                        }
                    }

                    if ($matchingFile && file_exists($extensionFile)) {
                        $file = $extensionFile;
                        $found = true;
                        break;
                    }
                }
            }

            if (!$found) {
                return ['success' => false, 'file' => $filename, 'error' => 'File not found in enabled extensions'];
            }
        }

        require_once $file;
        $className = pathinfo($file, PATHINFO_FILENAME);
        $className = preg_replace('/^\d+_/', '', $className); // Removes any leading digits and underscore

        if (!class_exists($className)) {
            throw DatabaseException::queryFailed(
                'MIGRATION_ERROR',
                "Migration class $className not found in $file"
            );
        }

        $migration = new $className();
        if (!$migration instanceof MigrationInterface) {
            throw BusinessLogicException::operationNotAllowed(
                'migration_validation',
                "Migration $className must implement MigrationInterface"
            );
        }

        try {
            // Run migration rollback - operations will execute immediately
            $migration->down($this->schema);

            // Delete migration record after schema operations complete
            $this->db->table(self::VERSION_TABLE)->where('migration', $filename)->delete();

            return ['success' => true, 'file' => $filename];
        } catch (\Exception $e) {
            error_log("Rollback failed: " . $e->getMessage());
            return ['success' => false, 'file' => $filename, 'error' => $e->getMessage()];
        }
    }

    /**
     * Execute migration
     *
     * @param MigrationInterface $migration Migration to execute
     */
    public function executeMigration(MigrationInterface $migration): void
    {
        // Run migration - operations execute immediately
        $migration->up($this->schema);
    }

    /**
     * Execute migration rollback
     *
     * @param MigrationInterface $migration Migration to rollback
     */
    public function executeRollback(MigrationInterface $migration): void
    {
        // Run migration rollback - operations execute immediately
        $migration->down($this->schema);
    }
}
