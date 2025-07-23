<?php

namespace Glueful\Console\Commands\Migrate;

use Glueful\Console\BaseCommand;
use Glueful\Database\Migrations\MigrationManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migrate Run Command
 * Executes pending database migrations with enhanced Symfony Console features:
 * - Proper argument validation
 * - Interactive confirmations
 * - Progress bars for multiple migrations
 * - Enhanced output formatting
 * @package Glueful\Console\Commands\Migrate
 */
#[AsCommand(
    name: 'migrate:run',
    description: 'Run pending database migrations'
)]
class RunCommand extends BaseCommand
{
    private MigrationManager $migrationManager;

    public function __construct()
    {
        parent::__construct();
        $this->migrationManager = $this->getService(MigrationManager::class);
    }

    protected function configure(): void
    {
        $this->setDescription('Run pending database migrations')
             ->setHelp('This command executes all pending database migrations in sequence.')
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force execution in production environment'
             )
             ->addOption(
                 'dry-run',
                 null,
                 InputOption::VALUE_NONE,
                 'Show what would be executed without running'
             )
             ->addOption(
                 'pretend',
                 null,
                 InputOption::VALUE_NONE,
                 'Alias for --dry-run'
             )
             ->addOption(
                 'batch',
                 'b',
                 InputOption::VALUE_REQUIRED,
                 'Specify batch number for grouping migrations'
             )
             ->addOption(
                 'path',
                 'p',
                 InputOption::VALUE_REQUIRED,
                 'Run migrations from custom directory'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $dryRun = $input->getOption('dry-run') || $input->getOption('pretend');
        $batch = $input->getOption('batch');
        $path = $input->getOption('path');

        // Production safety check
        if (!$force && !$this->confirmProduction('run database migrations')) {
            return self::FAILURE;
        }

        try {
            // Get pending migrations
            $pendingMigrations = $this->migrationManager->getPendingMigrations();

            if (empty($pendingMigrations)) {
                $this->info('No pending migrations found.');
                return self::SUCCESS;
            }

            $this->info(sprintf('Found %d pending migration(s)', count($pendingMigrations)));

            if ($dryRun) {
                $this->warning('DRY RUN MODE - No actual migrations will be executed');
                $this->listPendingMigrations($pendingMigrations);
                return self::SUCCESS;
            }

            // Confirm execution if not forced
            if (
                !$force && !$this->confirm(
                    sprintf('Do you want to run %d migration(s)?', count($pendingMigrations)),
                    false
                )
            ) {
                $this->info('Migration cancelled.');
                return self::SUCCESS;
            }

            // Execute migrations with progress bar
            $this->executeMigrationsWithProgress($pendingMigrations, (int) $batch);

            $this->success('All migrations executed successfully!');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Migration execution failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function listPendingMigrations(array $migrations): void
    {
        $headers = ['Migration', 'File'];
        $rows = [];

        foreach ($migrations as $migration) {
            $rows[] = [
                $migration['name'] ?? 'Unknown',
                $migration['file'] ?? 'Unknown'
            ];
        }

        $this->table($headers, $rows);
    }

    private function executeMigrationsWithProgress(array $migrations, ?int $batch): void
    {
        $this->progressBar(count($migrations), function ($progressBar) use ($migrations) {
            foreach ($migrations as $migrationFile) {
                $this->line(sprintf('Running: %s', basename($migrationFile)));

                // Use the migrate method which handles single file execution
                $result = $this->migrationManager->migrate($migrationFile);

                if (!empty($result['applied'])) {
                    $this->line(sprintf('✓ Completed: %s', basename($migrationFile)));
                } elseif (!empty($result['failed'])) {
                    throw new \Exception(sprintf(
                        'Failed to run migration: %s',
                        basename($migrationFile)
                    ));
                }

                $progressBar->advance();
            }
        });
    }
}
