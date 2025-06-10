<?php

declare(strict_types=1);

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\QueryBuilder;
use Glueful\Database\Connection;
use RuntimeException;

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
    /** @var SchemaManager Database schema manager for table operations */
    private SchemaManager $schema;

    /** @var QueryBuilder Query builder for database operations */
    private QueryBuilder $db;

    /** @var string Directory containing migration files */
    private string $migrationsPath;

    /** @var string Name of migrations tracking table */
    private const VERSION_TABLE = 'migrations';

    /**
     * Initialize migration manager
     *
     * Sets up schema manager and ensures version table exists.
     *
     * @param string|null $migrationsPath Custom path to migrations directory
     * @throws RuntimeException If database connection fails
     */
    public function __construct(?string $migrationsPath = null)
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());
        $this->schema = $connection->getSchemaManager();

        $this->migrationsPath = $migrationsPath ?? config('app.paths.migrations');
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
     */
    private function ensureVersionTable(): void
    {
        $this->schema
        ->createTable(self::VERSION_TABLE, [
            'id' => 'INTEGER PRIMARY KEY AUTO_INCREMENT',
            'migration' => 'VARCHAR(255) NOT NULL',
            'batch' => 'INTEGER NOT NULL',
            'applied_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'checksum' => 'VARCHAR(64) NOT NULL',
            'description' => 'TEXT'
        ])->addIndex([
            'type' => 'UNIQUE',
            'column' => 'migration',
            'table' => self::VERSION_TABLE
        ]);
    }

    /**
     * Get pending migrations
     *
     * Returns list of migration files that haven't been executed:
     * - Scans migrations directory for .php files
     * - Scans extensions migration directories
     * - Compares against applied migrations
     * - Returns array of pending migration paths
     *
     * @return array<string> List of pending migration file paths
     */
    public function getPendingMigrations(): array
    {
        $applied = $this->getAppliedMigrations();

        // Get migrations from main directory
        $files = glob($this->migrationsPath . '/*.php');

        // Get migrations from extensions
        $extensionsDir = config('app.paths.project_extensions');
        if (is_dir($extensionsDir)) {
            // Get all extension directories
            $extensions = array_filter(glob($extensionsDir . '/*'), 'is_dir');

            foreach ($extensions as $extension) {
                $migrationDir = $extension . 'migrations';
                if (is_dir($migrationDir)) {
                    $extensionFiles = glob($migrationDir . '/*.php');
                    $files = array_merge($files, $extensionFiles);
                }
            }
        }

        return array_filter($files, fn($file) => !in_array(basename($file), $applied));
    }

    /**
     * Get list of applied migrations
     *
     * @return array<string> List of applied migration filenames
     */
    private function getAppliedMigrations(): array
    {
        $result = $this->db
        ->select(self::VERSION_TABLE, ['migration'])
        ->where([])
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
     * Run migrations
     *
     * Executes pending migrations in order. Can run either:
     * - All pending migrations
     * - Specific migration file
     *
     * @param string|null $specificFile Optional specific migration to run
     * @return array{
     *     applied: array<string>,
     *     failed: array<string>
     * } Migration results
     */
    public function migrate(?string $specificFile = null): array
    {
        $results = ['applied' => [], 'failed' => []];
        if ($specificFile) {
            $status = $this->runMigration($specificFile);
            if ($status['success']) {
                $results['applied'][] = $status['file'];
            } else {
                $results['failed'][] = $status['file'];
            }
            return $results;
        }

        $batch = $this->getNextBatchNumber();

        foreach ($this->getPendingMigrations() as $file) {
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
     * 4. Records successful execution
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
                throw new RuntimeException("Migration class $className not found in $file");
            }
            $fullClassName = $className;
        }

        $migration = new $fullClassName();
        if (!$migration instanceof MigrationInterface) {
            throw new RuntimeException("Migration $fullClassName must implement MigrationInterface");
        }

        $filename = basename($file);
        $checksum = hash_file('sha256', $file);

        try {
            // Make sure no transaction is active before starting a new one
            if ($this->db->rollBack()) {
                error_log("Rolling back existing transaction");
            }

            // Run migration
            $migration->up($this->schema);

            $this->db->insert(
                self::VERSION_TABLE,
                [
                    'migration' => $filename,
                    'batch' => $batch ?? $this->getNextBatchNumber(),
                    'checksum' => $checksum,
                    'description' => $migration->getDescription()
                ]
            );

            $this->db->commit();
            return ['success' => true, 'file' => $filename];
        } catch (\Exception $e) {
            $this->db->rollBack();
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
        ->select(self::VERSION_TABLE, [
            $this->db->raw("MAX(batch) AS max_batch")
        ])
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
        ->select(self::VERSION_TABLE, ['migration'])
        ->orderBy([
            'batch' => 'DESC',
            'id' => 'DESC'
        ])
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

        // If not found in main directory, check in extension directories
        if (!file_exists($file)) {
            $extensionsDir = dirname(__DIR__, 3) . '/extensions';
            $found = false;

            if (is_dir($extensionsDir)) {
                $extensions = array_filter(glob($extensionsDir . '/*'), 'is_dir');

                foreach ($extensions as $extension) {
                    $migrationDir = $extension . '/migrations';
                    $extensionFile = $migrationDir . '/' . $filename;

                    if (is_dir($migrationDir) && file_exists($extensionFile)) {
                        $file = $extensionFile;
                        $found = true;
                        break;
                    }
                }
            }

            if (!$found) {
                return ['success' => false, 'file' => $filename, 'error' => 'File not found'];
            }
        }

        require_once $file;
        $className = pathinfo($file, PATHINFO_FILENAME);
        $className = preg_replace('/^\d+_/', '', $className); // Removes any leading digits and underscore

        if (!class_exists($className)) {
            throw new RuntimeException("Migration class $className not found in $file");
        }

        $migration = new $className();
        if (!$migration instanceof MigrationInterface) {
            throw new RuntimeException("Migration $className must implement MigrationInterface");
        }

        try {
            $migration->down($this->schema);

            // Delete using schema manager
            $this->db->delete(self::VERSION_TABLE, ['migration' => $filename]);

            $this->db->commit();
            return ['success' => true, 'file' => $filename];
        } catch (\Exception $e) {
            $this->db->rollBack();
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
        try {
            $migration->up($this->schema);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Execute migration rollback
     *
     * @param MigrationInterface $migration Migration to rollback
     */
    public function executeRollback(MigrationInterface $migration): void
    {
        try {
            $migration->down($this->schema);
            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
