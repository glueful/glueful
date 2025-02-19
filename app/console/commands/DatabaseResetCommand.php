<?php

namespace App\Console\Commands;

use App\Console\Command;
use PDO;
use Glueful\Api\Library\Utils;

class DatabaseResetCommand extends Command
{
    private PDO $db;
    
    public function getName(): string
    {
        return 'db:reset';
    }

    public function getDescription(): string
    {
        return 'Reset database to clean state';
    }

    public function getHelp(): string
    {
        return <<<HELP
Usage:
  db:reset [options]

Description:
  Resets the database by dropping all tables and re-running migrations.
  WARNING: This is a destructive operation that will delete all data!

Options:
  --force     Required flag to confirm database reset
  -h, --help  Display this help message

Example:
  php glueful db:reset --force
HELP;
    }

    public function execute(array $args = []): void
    {
        if (!in_array('--force', $args)) {
            $this->error("This will delete all data! Use --force to confirm.");
            return;
        }

        try {
            $this->db = Utils::getMySQLConnection();
            
            // Disable foreign key checks
            $this->db->exec('SET FOREIGN_KEY_CHECKS = 0');
            
            // Get all tables
            $tables = $this->db->query("
                SELECT TABLE_NAME 
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
            ")->fetchAll(PDO::FETCH_COLUMN);
            
            // Drop each table
            foreach ($tables as $table) {
                $this->db->exec("DROP TABLE IF EXISTS `$table`");
                $this->info("Dropped table: $table");
            }
            
            // Re-enable foreign key checks
            $this->db->exec('SET FOREIGN_KEY_CHECKS = 1');
            
            // Run migrations
            $migrate = new MigrateCommand();
            $migrate->execute();
            
            $this->info("Database reset complete!");
            
        } catch (\Exception $e) {
            $this->error("Reset failed: " . $e->getMessage());
        }
    }
}
