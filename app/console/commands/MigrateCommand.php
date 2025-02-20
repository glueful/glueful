<?php

namespace App\Console\Commands;

use App\Console\Command;
use PDO;
use Glueful\Api\Library\Utils;

/**
 * Database Migration Command
 * 
 * Handles database schema migrations by executing SQL files
 * in sequential order while tracking migration history.
 */
class MigrateCommand extends Command
{
    /** @var PDO Database connection */
    private PDO $db;
    
    /**
     * Get command name
     * 
     * @return string Command identifier
     */
    public function getName(): string
    {
        return 'db:migrate';
    }

    /**
     * Get command description
     * 
     * @return string Short command description
     */
    public function getDescription(): string
    {
        return 'Run database migrations to update schema';
    }

    /**
     * Get detailed help
     * 
     * @return string Full command documentation
     */
    public function getHelp(): string
    {
        return <<<HELP
Usage:
  db:migrate [options]

Description:
  Runs all pending database migrations in sequential order.
  Tracks migration history in schema_versions table.

Options:
  -h, --help       Display this help message
  --force          Skip confirmation for production environment
  --dry-run        Show which migrations would run without executing them

Examples:
  php glueful db:migrate
  php glueful db:migrate --force
  php glueful db:migrate --dry-run
HELP;
    }

    /**
     * Execute migration command
     * 
     * Runs pending migrations in order, tracking status.
     * 
     * @param array $args Command line arguments
     */
    public function execute(array $args = []): void
    {
        $this->db = Utils::getMySQLConnection();
        $this->ensureVersioningTable();
        
        $migrations = $this->getPendingMigrations();
        
        foreach ($migrations as $migration) {
            $this->runMigration($migration);
        }
    }
    
    /**
     * Create version tracking table
     * 
     * Ensures schema_versions table exists for migration history.
     */
    private function ensureVersioningTable(): void
    {
        $sql = file_get_contents(__DIR__ . '/../../../database/tables/schema_versions.sql');
        $this->db->exec($sql);
    }
    
    /**
     * Get pending migrations
     * 
     * Returns array of migration files that haven't been applied.
     * 
     * @return array<string> Pending migration file paths
     */
    private function getPendingMigrations(): array
    {
        $applied = $this->db->query("SELECT migration_file FROM schema_versions WHERE status = 'success'")->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(__DIR__ . '/../../../database/migrations/*.sql');
        
        return array_filter($files, fn($file) => !in_array(basename($file), $applied));
    }
    
    /**
     * Run single migration
     * 
     * Executes migration file and records its status.
     * 
     * @param string $file Migration file path
     * @throws \Exception If migration fails
     */
    private function runMigration(string $file): void
    {
        $version = basename($file);
        $sql = file_get_contents($file);
        $checksum = hash('sha256', $sql);
        
        try {
            $this->db->beginTransaction();
            $this->db->exec($sql);
            
            $stmt = $this->db->prepare("
                INSERT INTO schema_versions 
                (version, migration_file, checksum, applied_by) 
                VALUES (?, ?, ?, ?)
            ");
            
            $stmt->execute([
                date('YmdHis'), 
                $version,
                $checksum,
                get_current_user()
            ]);
            
            $this->db->commit();
            $this->info("✓ Applied migration: $version");
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            $this->error("Failed to apply $version: " . $e->getMessage());
        }
    }
}