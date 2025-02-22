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
        $this->db =  SchemaManagerFactory::getConnection();
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
        return $this->db->query("SELECT migration FROM " . self::VERSION_TABLE)
            ->fetchAll(PDO::FETCH_COLUMN);
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

        $className = pathinfo($file, PATHINFO_FILENAME);
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
            $this->db->beginTransaction();

            // Run migration
            $migration->up($this->schema);

            // Record migration
            $stmt = $this->db->prepare("
                INSERT INTO " . self::VERSION_TABLE . "
                (migration, batch, checksum, description)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $filename,
                $batch ?? $this->getNextBatchNumber(),
                $checksum,
                $migration->getDescription()
            ]);

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
        $result = $this->db->query("SELECT MAX(batch) FROM " . self::VERSION_TABLE)
            ->fetchColumn();
        return (int)$result + 1;
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
        $stmt = $this->db->prepare("
            SELECT migration FROM " . self::VERSION_TABLE . "
            ORDER BY batch DESC, id DESC
            LIMIT ?
        ");
        $stmt->execute([$steps]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
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
            $this->db->beginTransaction();
            
            $migration->down($this->schema);
            
            $stmt = $this->db->prepare("DELETE FROM " . self::VERSION_TABLE . " WHERE migration = ?");
            $stmt->execute([$filename]);
            
            $this->db->commit();
            return ['success' => true, 'file' => $filename];

        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("Rollback failed: " . $e->getMessage());
            return ['success' => false, 'file' => $filename, 'error' => $e->getMessage()];
        }
    }
}
