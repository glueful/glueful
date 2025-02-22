<?php

declare(strict_types=1);

namespace Glueful\App\Migrations;

use Glueful\App\Migrations\MigrationInterface;
use Glueful\Api\Schemas\SchemaManager;
use Glueful\Api\Schemas\SchemaManagerFactory;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Migration Manager
 * 
 * Manages database schema migrations and version tracking.
 * Provides functionality for:
 * - Running new migrations
 * - Rolling back applied migrations
 * - Tracking migration history
 * - Managing migration batches
 * - Verifying migration integrity
 * - Handling migration dependencies
 * 
 * Usage:
 * ```php
 * $manager = new MigrationManager();
 * 
 * // Run all pending migrations
 * $result = $manager->migrate();
 * 
 * // Run specific migration
 * $result = $manager->migrate('/path/to/migration.php');
 * 
 * // Rollback last migration
 * $result = $manager->rollback();
 * ```
 */
class MigrationManager
{
    /** @var SchemaManager Database schema manager instance */
    private SchemaManager $schema;

    /** @var PDO Active database connection */
    private PDO $db;

    /** @var string Path to migration files directory */
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
    public function __construct(string $migrationsPath = null)
    {
        $this->schema = SchemaManagerFactory::create();
        $this->migrationsPath = $migrationsPath ?? dirname(__DIR__, 2) . '/database/migrations';
        $this->ensureVersionTable();
    }

    /**
     * Create migration history table
     * 
     * Ensures table exists for tracking:
     * - Applied migrations
     * - Batch numbers
     * - File checksums
     * - Application timestamps
     * - Migration descriptions
     */
    private function ensureVersionTable(): void
    {
        $this->schema->createTable(self::VERSION_TABLE, [
            'id' => 'INTEGER PRIMARY KEY AUTO_INCREMENT',
            'migration' => 'VARCHAR(255) NOT NULL',
            'batch' => 'INTEGER NOT NULL',
            'applied_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'checksum' => 'VARCHAR(64) NOT NULL',
            'description' => 'TEXT'
        ], [
            ['type' => 'UNIQUE', 'column' => 'migration']
        ]);
    }

    /**
     * Get list of pending migrations
     * 
     * Returns array of migration files that haven't been applied.
     * 
     * @return array<string> List of pending migration file paths
     */
    public function getPendingMigrations(): array
    {
        $applied = $this->getAppliedMigrations();
        $files = glob($this->migrationsPath . '/*.php');
        return array_filter($files, fn($file) => !in_array(basename($file), $applied));
    }

    /**
     * Get list of applied migrations
     * 
     * @return array<string> List of applied migration filenames
     */
    private function getAppliedMigrations(): array
    {
        // Use schema manager instead of direct PDO
        $result = $this->schema->getData(self::VERSION_TABLE, ['fields' => 'migration']);
        return array_column($result, 'migration');
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
        if ($specificFile) {
            return $this->runMigration($specificFile);
        }

        $results = ['applied' => [], 'failed' => []];
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
    private function runMigration(string $file, int $batch = null): array
    {
        require_once $file;

        $className = pathinfo($file, PATHINFO_FILENAME); // Gets "001_CreateInitialSchema"
        $className = preg_replace('/^\d+_/', '', $className); // Removes any leading digits and underscore
        
        if (!class_exists($className)) {
            throw new RuntimeException("Migration class $className not found in $file");
        }
  
        $migration = new $className();
        if (!$migration instanceof MigrationInterface) {
            throw new RuntimeException("Migration $className must implement MigrationInterface");
        }

        $filename = basename($file);
        $checksum = hash_file('sha256', $file);

        try {
            // Make sure no transaction is active before starting a new one
            if ($this->schema->rollBack()) {
                error_log("Rolling back existing transaction");
            }

            // Run migration
            $migration->up($this->schema);

            // Record migration using schema manager
            $this->schema->insert(self::VERSION_TABLE, [
                'migration' => $filename,
                'batch' => $batch ?? $this->getNextBatchNumber(),
                'checksum' => $checksum,
                'description' => $migration->getDescription()
            ]);

            $this->schema->commit();
            return ['success' => true, 'file' => $filename];

        } catch (\Exception $e) {
            $this->schema->rollBack();
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
        // Use schema manager for getting max batch number
        $result = $this->schema->getData(self::VERSION_TABLE, ['fields' => 'MAX(batch) as max_batch']);
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
        $result = $this->schema->getData(
            self::VERSION_TABLE,
            [
                'fields' => 'migration',
                'order' => 'batch DESC, id DESC',
                'limit' => $steps
            ]
        );
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
        $file = $this->migrationsPath . '/' . $filename;
        if (!file_exists($file)) {
            return ['success' => false, 'file' => $filename, 'error' => 'File not found'];
        }

        require_once $file;
        $className = pathinfo($file, PATHINFO_FILENAME);
        $migration = new $className();

        try {
            
            $migration->down($this->schema);
            
            // Delete using schema manager
            $this->schema->delete(self::VERSION_TABLE, ['migration' => $filename]);
            
            $this->schema->commit();
            return ['success' => true, 'file' => $filename];

        } catch (\Exception $e) {
            $this->schema->rollBack();
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
            $this->schema->commit();
        } catch (\Exception $e) {
            $this->schema->rollBack();
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
            $this->schema->commit();
        } catch (\Exception $e) {
            $this->schema->rollBack();
            throw $e;
        }
    }
}
