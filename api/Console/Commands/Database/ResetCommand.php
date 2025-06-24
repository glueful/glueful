<?php

namespace Glueful\Console\Commands\Database;

use Glueful\Console\BaseCommand;
use Glueful\Database\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Database Reset Command
 * Safely resets database to clean state with enhanced features:
 * - Multiple confirmation prompts
 * - Progress tracking
 * - Dry-run mode
 * - Foreign key handling
 * - Backup reminder
 * - Detailed operation logging
 * @package Glueful\Console\Commands\Database
 */
#[AsCommand(
    name: 'db:reset',
    description: 'Reset database to clean state (drops all tables)'
)]
class ResetCommand extends BaseCommand
{
    private Connection $connection;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Reset database to clean state (drops all tables)')
             ->setHelp('This command drops all tables in the database. Use with extreme caution!')
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force reset without confirmation prompts'
             )
             ->addOption(
                 'dry-run',
                 null,
                 InputOption::VALUE_NONE,
                 'Show what would be dropped without executing'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $dryRun = $input->getOption('dry-run');

        try {
            $this->connection = new Connection();
            $schema = $this->connection->getSchemaManager();

            // Get all tables
            $tables = $schema->getTables();

            if (empty($tables)) {
                $this->info('No tables found in the database.');
                return self::SUCCESS;
            }

            // Display warning
            $this->displayWarning($tables);

            if ($dryRun) {
                $this->displayDryRun($tables);
                return self::SUCCESS;
            }

            // Require force flag or confirmation
            if (!$force && !$this->confirmReset()) {
                $this->info('Database reset cancelled.');
                return self::SUCCESS;
            }

            // Additional production check
            if (!$force && !$this->confirmProduction('reset the entire database')) {
                return self::FAILURE;
            }

            // Execute reset
            $this->executeReset($tables);

            $this->success('Database reset complete!');
            $this->tip('Run "php glueful migrate:run" to rebuild the database structure.');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Database reset failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function displayWarning(array $tables): void
    {
        $this->warning('⚠️  DATABASE RESET WARNING ⚠️');
        $this->line('');
        $this->error('This operation will DELETE ALL DATA from your database!');
        $this->line('');
        $this->info(sprintf('Tables to be dropped: %d', count($tables)));

        if (count($tables) <= 10) {
            foreach ($tables as $table) {
                $this->line('  • ' . $table);
            }
        } else {
            // Show first 5 and last 5 tables
            $firstTables = array_slice($tables, 0, 5);
            $lastTables = array_slice($tables, -5);

            foreach ($firstTables as $table) {
                $this->line('  • ' . $table);
            }
            $this->line('  ... and ' . (count($tables) - 10) . ' more tables ...');
            foreach ($lastTables as $table) {
                $this->line('  • ' . $table);
            }
        }

        $this->line('');
        $this->warning('Make sure you have a backup before proceeding!');
    }

    private function displayDryRun(array $tables): void
    {
        $this->line('');
        $this->info('DRY RUN MODE - No changes will be made');
        $this->line('');
        $this->info('The following operations would be performed:');
        $this->line('');

        $this->line('1. Disable foreign key checks');
        $this->line('2. Drop tables in reverse order:');

        foreach (array_reverse($tables) as $index => $table) {
            $this->line(sprintf('   %d. DROP TABLE %s', $index + 1, $table));
        }

        $this->line('3. Re-enable foreign key checks');
    }

    private function confirmReset(): bool
    {
        $this->line('');

        // First confirmation
        if (!$this->confirm('Are you absolutely sure you want to reset the database?', false)) {
            return false;
        }

        // Second confirmation with typed response
        $confirmText = 'reset database';
        $response = $this->ask(
            sprintf('Type "%s" to confirm the database reset', $confirmText)
        );

        if ($response !== $confirmText) {
            $this->error('Confirmation text did not match.');
            return false;
        }

        return true;
    }

    private function executeReset(array $tables): void
    {
        $schema = $this->connection->getSchemaManager();
        $tableCount = count($tables);

        $this->info(sprintf('Dropping %d tables...', $tableCount));
        $this->line('');

        // Disable foreign key checks
        $this->line('Disabling foreign key checks...');
        $schema->disableForeignKeyChecks();

        // Drop tables with progress bar
        $this->progressBar($tableCount, function ($progressBar) use ($tables, $schema) {
            foreach (array_reverse($tables) as $table) {
                try {
                    $schema->dropTable($table);
                    $this->line(sprintf('✓ Dropped table: %s', $table));
                } catch (\Exception $e) {
                    $this->error(sprintf('✗ Failed to drop table %s: %s', $table, $e->getMessage()));
                    // Continue with other tables
                }
                $progressBar->advance();
            }
        });

        // Re-enable foreign key checks
        $this->line('');
        $this->line('Re-enabling foreign key checks...');
        $schema->enableForeignKeyChecks();
    }
}
