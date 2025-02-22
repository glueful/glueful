<?php

namespace Glueful\App\Console\Commands;

use Glueful\App\Console\Command;
use PDO;
use Glueful\Api\Library\Utils;

/**
 * Database Status Command
 * 
 * Displays current database connection status and statistics.
 * Shows connection, server info, database size, and table counts.
 */
class DatabaseStatusCommand extends Command
{
    /** @var PDO Database connection instance */
    private PDO $db;
    
    /**
     * Get command name
     * 
     * @return string Command identifier
     */
    public function getName(): string
    {
        return 'db:status';
    }

    /**
     * Get command description
     * 
     * @return string Brief command description
     */
    public function getDescription(): string
    {
        return 'Show database connection status and statistics';
    }

    /**
     * Get detailed help
     * 
     * @return string Command usage instructions
     */
    public function getHelp(): string
    {
        return <<<HELP
Usage:
  db:status [options]

Description:
  Shows current database connection status and statistics including:
  - Connection status
  - Server information
  - Database size
  - Table count

Options:
  -h, --help   Display this help message
HELP;
    }

    /**
     * Execute status command
     * 
     * Retrieves and displays database statistics.
     * Shows connection status, server info, size, and table count.
     * 
     * @param array $args Command arguments
     * @throws \Exception If database connection fails
     */
    public function execute(array $args = []): void
    {
        try {
            $this->db = Utils::getMySQLConnection();
            
            // Check connection
            $this->info("Database Connection: ✓ Connected");
            
            // Get server info
            $this->info("Server Info: " . $this->db->getAttribute(PDO::ATTR_SERVER_INFO));
            $this->info("Server Version: " . $this->db->getAttribute(PDO::ATTR_SERVER_VERSION));
            
            // Get database size
            $size = $this->db->query("
                SELECT Round(Sum(data_length + index_length) / 1024 / 1024, 1)
                FROM information_schema.tables
                WHERE table_schema = DATABASE()
            ")->fetchColumn();
            
            $this->info("Database Size: {$size}MB");
            
            // Get tables count
            $tableCount = $this->db->query("
                SELECT COUNT(*) FROM information_schema.tables 
                WHERE table_schema = DATABASE()
            ")->fetchColumn();
            
            $this->info("Total Tables: {$tableCount}");
            
        } catch (\Exception $e) {
            $this->error("Database Connection Failed: " . $e->getMessage());
        }
    }
}
