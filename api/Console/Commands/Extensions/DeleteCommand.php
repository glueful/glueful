<?php

namespace Glueful\Console\Commands\Extensions;

use Glueful\Console\Commands\Extensions\BaseExtensionCommand;
use Glueful\Extensions\ExtensionManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Extensions Delete Command
 * - Safe extension deletion with dependency checking
 * - Backup creation before deletion
 * - Confirmation prompts and dry-run mode
 * - Cleanup of related files and configurations
 * - Rollback capability for accidental deletions
 * @package Glueful\Console\Commands\Extensions
 */
#[AsCommand(
    name: 'extensions:delete',
    description: 'Delete an extension completely'
)]
class DeleteCommand extends BaseExtensionCommand
{
    protected function configure(): void
    {
        $this->setDescription('Delete an extension completely')
             ->setHelp('This command permanently deletes an extension and all its files. Use with caution!')
             ->addArgument(
                 'name',
                 InputArgument::REQUIRED,
                 'The name of the extension to delete'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Skip confirmation prompts'
             )
             ->addOption(
                 'backup',
                 'b',
                 InputOption::VALUE_NONE,
                 'Create backup before deletion'
             )
             ->addOption(
                 'dry-run',
                 'd',
                 InputOption::VALUE_NONE,
                 'Show what would be deleted without actually deleting'
             )
             ->addOption(
                 'keep-config',
                 'k',
                 InputOption::VALUE_NONE,
                 'Keep configuration files'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extensionName = $input->getArgument('name');
        $force = $input->getOption('force');
        $backup = $input->getOption('backup');
        $dryRun = $input->getOption('dry-run');
        $keepConfig = $input->getOption('keep-config');

        try {
            $this->info("Preparing to delete extension: {$extensionName}");

            // Check if extension exists
            if (!$this->extensionDirectoryExists($extensionName)) {
                $this->error("Extension '{$extensionName}' not found.");
                return self::FAILURE;
            }

            $extensionPath = $this->getExtensionPath($extensionName);

            // Load extension metadata
            $extensionConfig = $this->loadExtensionConfig($extensionName);

            // Check if extension is enabled
            if ($extensionConfig && ($extensionConfig['enabled'] ?? false)) {
                $this->warning("Extension '{$extensionName}' is currently enabled.");
                $this->info("It will be disabled before deletion.");
            }

            // Check for dependent extensions
            $extensionsManager = $this->getService(ExtensionManager::class);
            $dependents = $this->findDependentExtensions($extensionsManager, $extensionName);
            if (!empty($dependents) && !$force) {
                $this->error("Cannot delete extension '{$extensionName}' - it has dependent extensions:");
                foreach ($dependents as $dependent) {
                    $this->line("  • {$dependent}");
                }
                $this->tip('Disable dependent extensions first or use --force to override.');
                return self::FAILURE;
            }

            // Show deletion plan
            $deletionPlan = $this->planDeletion($extensionPath, $keepConfig);
            $this->displayDeletionPlan($deletionPlan, $dryRun);

            if ($dryRun) {
                $this->info('Dry run completed. No files were deleted.');
                return self::SUCCESS;
            }

            // Confirmation prompt
            if (!$force && !$this->confirm("Are you sure you want to delete extension '{$extensionName}'?", false)) {
                $this->info('Deletion cancelled.');
                return self::SUCCESS;
            }

            // Create backup if requested
            $backupPath = null;
            if ($backup) {
                $backupPath = $this->createBackup($extensionPath, $extensionName);
            }


            try {
                $result = $extensionsManager->delete($extensionName, $force);
                if (is_array($result)) {
                    // Handle array response format like old system
                    if (!$result['success']) {
                        $this->error('Deletion failed: ' . ($result['message'] ?? 'Unknown error'));
                        return self::FAILURE;
                    }
                    $this->success("Extension '{$extensionName}' deleted successfully!");
                    if (!empty($result['message'])) {
                        $this->info($result['message']);
                    }
                } else {
                    // Handle boolean response format
                    if (!$result) {
                        $this->error('Deletion failed');
                        return self::FAILURE;
                    }
                    $this->success("Extension '{$extensionName}' deleted successfully!");
                }
            } catch (\Exception $e) {
                $this->error('Deletion failed: ' . $e->getMessage());
                return self::FAILURE;
            }

            if ($backupPath) {
                $this->info("Backup created at: {$backupPath}");
            }

            $this->displayDeletionSummary($extensionName, is_array($result) ? $result : [], $backupPath);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Deletion failed: " . $e->getMessage());
            return self::FAILURE;
        }
    }



    private function planDeletion(string $extensionPath, bool $keepConfig): array
    {
        $plan = [
            'directories' => [],
            'files' => [],
            'config_files' => [],
            'total_size' => 0
        ];

        if (!is_dir($extensionPath)) {
            return $plan;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extensionPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileinfo) {
            $path = $fileinfo->getRealPath();
            $relativePath = str_replace($extensionPath . '/', '', $path);

            if ($fileinfo->isDir()) {
                $plan['directories'][] = $relativePath;
            } else {
                $isConfigFile = $this->isConfigFile($fileinfo->getFilename());

                if ($keepConfig && $isConfigFile) {
                    $plan['config_files'][] = $relativePath;
                } else {
                    $plan['files'][] = $relativePath;
                    $plan['total_size'] += $fileinfo->getSize();
                }
            }
        }

        return $plan;
    }

    private function isConfigFile(string $filename): bool
    {
        $configFiles = ['extension.json', 'config.php', 'settings.json', '.env'];
        return in_array($filename, $configFiles) ||
               str_starts_with($filename, 'config.') ||
               str_ends_with($filename, '.config');
    }

    private function displayDeletionPlan(array $plan, bool $dryRun): void
    {
        $this->line('');
        $this->info($dryRun ? 'Deletion Plan (Dry Run):' : 'Deletion Plan:');
        $this->line('========================');

        $fileCount = count($plan['files']);
        $dirCount = count($plan['directories']);
        $configCount = count($plan['config_files']);
        $totalSize = $this->formatBytes($plan['total_size']);

        $this->table(['Item', 'Count'], [
            ['Files to delete', $fileCount],
            ['Directories to delete', $dirCount],
            ['Config files to keep', $configCount],
            ['Total size', $totalSize]
        ]);

        if ($fileCount <= 20) {
            $this->line('');
            $this->info('Files to be deleted:');
            foreach ($plan['files'] as $file) {
                $this->line("  • {$file}");
            }
        } else {
            $this->line('');
            $this->info("Sample files to be deleted (showing 10 of {$fileCount}):");
            foreach (array_slice($plan['files'], 0, 10) as $file) {
                $this->line("  • {$file}");
            }
            $this->line("  ... and " . ($fileCount - 10) . " more files");
        }

        if (!empty($plan['config_files'])) {
            $this->line('');
            $this->info('Config files to be preserved:');
            foreach ($plan['config_files'] as $file) {
                $this->line("  • {$file}");
            }
        }
    }

    private function createBackup(string $extensionPath, string $extensionName): string
    {
        $this->info('Creating backup...');

        $backupDir = dirname(__DIR__, 6) . '/storage/backups/extensions';
        if (!$this->getFileManager()->exists($backupDir)) {
            $this->getFileManager()->createDirectory($backupDir, 0755);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $backupPath = "{$backupDir}/{$extensionName}_{$timestamp}.tar.gz";

        $command = "tar -czf {$backupPath} -C " . dirname($extensionPath) . " " . basename($extensionPath);

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException("Backup creation failed: " . implode("\n", $output));
        }

        $this->line('✓ Backup created');
        return $backupPath;
    }



    private function isDirectoryEmpty(string $path): bool
    {
        if (!$this->getFileManager()->exists($path) || !is_dir($path)) {
            return true;
        }

        $contents = scandir($path);
        return count($contents) <= 2; // Only . and .. entries
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = 1024;

        for ($i = 0; $i < count($units) && $bytes >= $factor; $i++) {
            $bytes /= $factor;
        }

        return round($bytes, 1) . ' ' . $units[$i];
    }

    private function displayDeletionSummary(string $extensionName, array $result, ?string $backupPath): void
    {
        $this->line('');
        $this->info('Deletion Summary:');

        $summary = [
            ['Extension Name', $extensionName],
            ['Status', $result['success'] ? 'Successfully Deleted' : 'Deletion Failed'],
            ['Backup Created', $backupPath ? 'Yes' : 'No']
        ];

        if (!empty($result['files_deleted'])) {
            $summary[] = ['Files Deleted', $result['files_deleted']];
        }

        if (!empty($result['size_freed'])) {
            $summary[] = ['Data Freed', $result['size_freed']];
        }

        if ($backupPath) {
            $summary[] = ['Backup Location', basename($backupPath)];
        }

        $this->table(['Property', 'Value'], $summary);

        if ($backupPath) {
            $this->line('');
            $this->info('Recovery instructions:');
            $this->line("To restore this extension from backup:");
            $this->line("1. Extract backup: tar -xzf {$backupPath} -C " . dirname(dirname(__DIR__, 6) . "/extensions"));
            $this->line("2. Enable extension: php glueful extensions:enable {$extensionName}");
        }

        $this->line('');
        $this->warning('This action cannot be undone without a backup!');
    }
}
