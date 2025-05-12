<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\Connection;

/**
 * Database Status Command
 *
 * Provides detailed database diagnostics and statistics:
 * - Checks connection health
 * - Shows server information
 * - Displays database metrics
 * - Reports table statistics
 * - Calculates storage usage
 * - Monitors performance
 * - Lists configuration
 *
 * @package Glueful\Console\Commands
 */
class DatabaseStatusCommand extends Command
{
    private SchemaManager $schema;

    /**
     * Get Command Name
     *
     * Returns command identifier:
     * - Used in CLI as `php glueful db:status`
     * - Unique across command set
     * - Follows naming standards
     *
     * @return string Command identifier
     */
    public function getName(): string
    {
        return 'db:status';
    }

    /**
     * Get Command Description
     *
     * Provides brief overview:
     * - Single line summary
     * - Shows in command lists
     * - Describes main purpose
     *
     * @return string Brief description
     */
    public function getDescription(): string
    {
        return 'Show database connection status and statistics';
    }

    /**
     * Get Command Help
     *
     * Provides detailed usage information:
     * - Shows command syntax
     * - Lists available options
     * - Includes examples
     * - Documents output format
     *
     * @return string Detailed help text
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
     * Execute Status Command
     *
     * Performs database diagnostics:
     * - Checks connection status
     * - Gets server version info
     * - Counts total tables
     * - Calculates database size
     * - Shows table statistics
     * - Reports storage usage
     * - Handles connection errors
     *
     * @param array $args Command line arguments
     * @throws \RuntimeException If database connection fails
     * @return int Exit code (0 for success)
     */
    public function execute(array $args = []): int
    {
        try {
            $connection = new Connection();
            $this->schema = $connection->getSchemaManager();

            // Check connection
            $this->info("Database Connection: ✓ Connected");

            // Get server version
            $version = $this->schema->getVersion();
            $this->info("Server Version: " . $version);

            // Get tables using SHOW TABLES
            $tables = $this->schema->getTables();
            $tableCount = count($tables);

            $this->info("Total Tables: " . $tableCount);

            // Calculate total database size
            $totalSize = 0;
            foreach ($tables as $table) {
                $size = $this->schema->getTableSize($table);
                $this->info("Table Size: " . $table . ":" . $size);
                $totalSize += $size;
            }

            // Convert bytes to appropriate unit
            $units = ['B', 'KB', 'MB', 'GB'];
            $i = 0;
            while ($totalSize >= 1024 && $i < count($units) - 1) {
                $totalSize /= 1024;
                $i++;
            }

            $this->info("Database Size: " . round($totalSize, 2) . " " . $units[$i]);

            // Show individual table sizes
            $this->info("\nTable Sizes:");
            foreach ($tables as $table) {
                $size = $this->schema->getTableSize($table);

                // Convert bytes to appropriate unit
                $i = 0;
                while ($size >= 1024 && $i < count($units) - 1) {
                    $size /= 1024;
                    $i++;
                }

                $this->info(sprintf("  %-20s %8.2f %s", $table, $size, $units[$i]));
            }
        } catch (\Exception $e) {
            $this->error("Database Connection Failed: " . $e->getMessage());
            return Command::FAILURE; // Return error code
        }

        return Command::SUCCESS; // Return success code
    }
}
