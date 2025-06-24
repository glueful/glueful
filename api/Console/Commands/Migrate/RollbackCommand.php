<?php

namespace Glueful\Console\Commands\Migrate;

use Glueful\Console\BaseCommand;
use Glueful\Database\Migrations\MigrationManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migration Rollback Command
 * Rolls back database migrations with enhanced safety features:
 * - Step-based rollback control
 * - Production safety confirmations
 * - Progress tracking
 * - Detailed rollback information
 * @package Glueful\Console\Commands\Migrate
 */
#[AsCommand(
    name: 'migrate:rollback',
    description: 'Rollback database migrations'
)]
class RollbackCommand extends BaseCommand
{
    private MigrationManager $migrationManager;

    public function __construct()
    {
        parent::__construct();
        $this->migrationManager = $this->getService(MigrationManager::class);
    }

    protected function configure(): void
    {
        $this->setDescription('Rollback database migrations')
             ->setHelp('This command rolls back database migrations. ' .
                      'Use --steps to control how many migrations to rollback.')
             ->addOption(
                 'steps',
                 's',
                 InputOption::VALUE_REQUIRED,
                 'Number of migration steps to rollback',
                 1
             )
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
                 'Show what would be rolled back without executing'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $steps = (int) $input->getOption('steps');
        $force = $input->getOption('force');
        $dryRun = $input->getOption('dry-run');

        // Validate steps
        if ($steps < 1) {
            $this->error('Steps must be a positive integer.');
            return self::FAILURE;
        }

        // Production safety check
        if (!$force && !$this->confirmProduction('rollback database migrations')) {
            return self::FAILURE;
        }

        try {
            // Get applied migrations to potentially rollback
            $appliedMigrations = $this->migrationManager->getAppliedMigrationsList();

            if (empty($appliedMigrations)) {
                $this->info('No migrations to rollback.');
                return self::SUCCESS;
            }

            $this->info(sprintf('Found %d applied migration(s)', count($appliedMigrations)));

            if ($dryRun) {
                $this->warning('DRY RUN MODE - No actual rollbacks will be executed');
                $this->warning(sprintf('Would rollback %d step(s)', $steps));
                return self::SUCCESS;
            }

            // Confirm execution if not forced
            if (
                !$force && !$this->confirm(
                    sprintf('Do you want to rollback %d migration step(s)?', $steps),
                    false
                )
            ) {
                $this->info('Rollback cancelled.');
                return self::SUCCESS;
            }

            // Execute rollback using MigrationManager
            $result = $this->migrationManager->rollback($steps);

            // Display results
            if (!empty($result['reverted'])) {
                $this->success('Migrations rolled back successfully!');
                foreach ($result['reverted'] as $reverted) {
                    $this->line('✓ Rolled back: ' . $reverted);
                }
            } else {
                $this->info('No migrations were rolled back.');
            }

            if (!empty($result['failed'])) {
                $this->error('Some rollbacks failed:');
                foreach ($result['failed'] as $failed) {
                    $this->line('✗ Failed: ' . $failed);
                }
                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Rollback execution failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
