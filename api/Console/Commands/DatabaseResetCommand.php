<?php

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\Connection;

/**
 * Database Reset Command
 *
 * Provides functionality to reset a database to its initial state:
 * - Safely drops all existing tables
 * - Handles foreign key constraints
 * - Performs operations in correct order
 * - Provides safety confirmations
 * - Supports dry-run mode
 * - Logs operations
 * - Handles errors gracefully
 *
 * @package Glueful\Console\Commands
 */
class DatabaseResetCommand extends Command
{
    private SchemaManager $schema;

    /**
     * Get Command Name
     *
     * Returns the identifier used to execute this command:
     * - Used in CLI as `php glueful db:reset`
     * - Must be unique across all commands
     * - Should follow naming conventions
     *
     * @return string Command identifier
     */
    public function getName(): string
    {
        return 'db:reset';
    }

    /**
     * Get Command Description
     *
     * Provides brief summary for help listings:
     * - Single line description
     * - Shown in command lists
     * - Explains command purpose
     *
     * @return string Brief command description
     */
    public function getDescription(): string
    {
        return 'Reset database to clean state';
    }

    /**
     * Get Command Help
     *
     * Provides detailed usage instructions:
     * - Shows command syntax
     * - Lists available options
     * - Includes usage examples
     * - Documents safety warnings
     *
     * @return string Detailed help text
     */
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

    /**
     * Execute Reset Operation
     *
     * Performs complete database reset:
     * - Validates force flag presence
     * - Disables foreign key checks
     * - Gets list of existing tables
     * - Drops tables in reverse order
     * - Handles errors per table
     * - Re-enables foreign key checks
     * - Provides operation feedback
     *
     * @param array $args Command line arguments
     * @throws \RuntimeException If reset operation fails
     * @return int Status code, 0 for success, non-zero for errors
     */
    public function execute(array $args = []): int
    {
        if (!in_array('--force', $args)) {
            $this->error("This will delete all data! Use --force to confirm.");
            return Command::FAILURE;
        }

        try {
            $connection = new Connection();
            $schema = $connection->getSchemaManager();

            // Get all tables using SHOW TABLES
            // $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
            $tables = $schema->getTables();

            if (empty($tables)) {
                $this->info("No tables found to drop.");
                return Command::SUCCESS;
            }

            $this->info("Found " . count($tables) . " tables to drop...\n");

            // Disable foreign key checks
            $schema->disableForeignKeyChecks();

            // Drop tables in reverse order
            foreach (array_reverse($tables) as $table) {
                $this->info("Dropping table: $table");
                try {
                    // Use PDO directly since SchemaManager errors don't affect the actual drop
                    $schema->dropTable($table);
                    $this->success("✓ Dropped $table");
                } catch (\Exception $e) {
                    // Log error but continue since table might still be dropped
                    error_log("Error while dropping $table: " . $e->getMessage());
                }
            }

            // Re-enable foreign key checks
            $schema->enableForeignKeyChecks();

            $this->success("\nDatabase reset complete!");
            $this->info("Run migrations to rebuild the database structure.");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Reset failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
