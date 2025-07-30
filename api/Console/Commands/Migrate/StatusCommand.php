<?php

namespace Glueful\Console\Commands\Migrate;

use Glueful\Console\BaseCommand;
use Glueful\Database\Migrations\MigrationManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migration Status Command
 * Displays comprehensive migration status with enhanced formatting:
 * - Tabular display of all migrations
 * - Status indicators (Pending/Completed)
 * - Batch information
 * - Execution timestamps
 * @package Glueful\Console\Commands\Migrate
 */
#[AsCommand(
    name: 'migrate:status',
    description: 'Show the status of database migrations'
)]
class StatusCommand extends BaseCommand
{
    private MigrationManager $migrationManager;

    public function __construct()
    {
        parent::__construct();
        $this->migrationManager = $this->getService(MigrationManager::class);
    }

    protected function configure(): void
    {
        $this->setDescription('Show the status of database migrations')
             ->setHelp('This command displays a table showing all migrations and their current status.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->info('Migration Status');
            $this->line('');

            // Get migration status efficiently (single query)
            $status = $this->migrationManager->getMigrationStatus();
            $pendingMigrations = $status['pending'];
            $appliedMigrations = $status['applied'];

            if (empty($pendingMigrations) && empty($appliedMigrations)) {
                $this->warning('No migration files found.');
                $this->tip('Create your first migration with: php glueful migrate:create <migration_name>');
                return self::SUCCESS;
            }

            // Prepare table data
            $headers = ['Migration', 'Batch', 'Status', 'Executed At'];
            $rows = [];

            // Add applied migrations
            foreach ($appliedMigrations as $applied) {
                $rows[] = [
                    basename($applied),
                    '-', // Batch info not available from this method
                    '<info>✓ Completed</info>',
                    '-' // Execution date not available from this method
                ];
            }

            // Add pending migrations
            foreach ($pendingMigrations as $pending) {
                $rows[] = [
                    basename($pending),
                    '-',
                    '<comment>⏳ Pending</comment>',
                    '-'
                ];
            }

            // Display table
            $this->table($headers, $rows);

            // Summary
            $totalMigrations = count($pendingMigrations) + count($appliedMigrations);
            $completedMigrations = count($appliedMigrations);
            $pendingCount = count($pendingMigrations);

            $this->line('');
            $this->info('Summary:');
            $this->line(sprintf('  Total migrations: %d', $totalMigrations));
            $this->line(sprintf('  Completed: <info>%d</info>', $completedMigrations));
            $this->line(sprintf('  Pending: <comment>%d</comment>', $pendingCount));

            if ($pendingCount > 0) {
                $this->line('');
                $this->tip('Run pending migrations with: php glueful migrate:run');
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to get migration status: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
