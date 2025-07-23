<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Cache;

use Glueful\Cache\EdgeCacheService;
use Symfony\Component\Console\Attribute\AsCommand;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Edge Cache Purge Command
 * - Multiple purge strategies (URL, tag, pattern, all)
 * - Batch purge operations with progress tracking
 * - Cache analytics and statistics
 * - Validation and verification of purge operations
 * - Support for multiple edge cache providers
 * - Detailed reporting and logging
 * @package Glueful\Console\Commands\Cache
 */
#[AsCommand(
    name: 'cache:purge',
    description: 'Purge edge cache content with advanced management features'
)]
class PurgeCommand extends BaseCommand
{
    private EdgeCacheService $edgeCacheService;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Purge edge cache content with advanced management features')
             ->setHelp('This command provides comprehensive edge cache purging capabilities ' .
                      'including URL-specific, tag-based, pattern matching, and bulk operations.')
             ->addArgument(
                 'target',
                 InputArgument::OPTIONAL,
                 'Target to purge (URL, tag, or pattern)'
             )
             ->addOption(
                 'url',
                 'u',
                 InputOption::VALUE_REQUIRED,
                 'Purge specific URL from cache'
             )
             ->addOption(
                 'tag',
                 't',
                 InputOption::VALUE_REQUIRED,
                 'Purge content with specific cache tag'
             )
             // Pattern option removed - not supported by EdgeCacheService
             ->addOption(
                 'all',
                 'a',
                 InputOption::VALUE_NONE,
                 'Purge all cached content'
             )
             ->addOption(
                 'batch-file',
                 'b',
                 InputOption::VALUE_REQUIRED,
                 'File containing URLs/tags to purge (one per line)'
             )
             ->addOption(
                 'dry-run',
                 'd',
                 InputOption::VALUE_NONE,
                 'Show what would be purged without executing'
             )
             // Verify option removed - not supported by EdgeCacheService
             ->addOption(
                 'stats',
                 's',
                 InputOption::VALUE_NONE,
                 'Show cache statistics before and after purge'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force purge without confirmation prompts'
             )
             ->addOption(
                 'timeout',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Timeout for purge operations in seconds',
                 '30'
             )
             ->addOption(
                 'provider',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Specific edge cache provider to use'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $this->io->title('🚀 Edge Cache Purge Manager');

        // Check if edge caching is enabled
        if (!$this->edgeCacheService->isEnabled()) {
            $this->io->error('Edge caching is not enabled. Please enable it in your configuration first.');
            return self::FAILURE;
        }

        // Display provider information
        $provider = $this->edgeCacheService->getProvider();
        $this->io->text("Edge cache provider: <info>{$provider}</info>");
        $this->io->newLine();

        try {
            // Show cache statistics if requested
            if ($input->getOption('stats')) {
                $this->displayCacheStats('before');
            }

            // Determine purge operation
            $result = $this->executePurgeOperation($input);

            // Show statistics after purge if requested
            if ($input->getOption('stats') && $result === self::SUCCESS) {
                $this->io->newLine();
                $this->displayCacheStats('after');
            }

            return $result;
        } catch (\Exception $e) {
            $this->io->error('Purge operation failed: ' . $e->getMessage());
            if ($output->isVerbose()) {
                $this->io->text($e->getTraceAsString());
            }
            return self::FAILURE;
        }
    }

    private function initializeServices(): void
    {
        $this->edgeCacheService = $this->getService(EdgeCacheService::class);
    }

    private function executePurgeOperation(InputInterface $input): int
    {
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        if ($dryRun) {
            $this->io->warning('🧪 DRY RUN MODE - No actual purge operations will be performed');
        }

        // Handle different purge types
        if ($input->getOption('all')) {
            return $this->purgeAll($dryRun, $force);
        }

        if ($batchFile = $input->getOption('batch-file')) {
            return $this->purgeBatch($batchFile, $dryRun);
        }

        if ($url = $input->getOption('url')) {
            return $this->purgeUrl($url, $dryRun);
        }

        if ($tag = $input->getOption('tag')) {
            return $this->purgeTag($tag, $dryRun);
        }

        // Pattern purging removed - not available in original EdgeCacheService

        // Check if target argument provided
        $target = $input->getArgument('target');
        if ($target) {
            // Auto-detect target type
            return $this->purgeTarget($target, $dryRun);
        }

        // No purge option specified
        $this->io->warning('No purge target specified. Please use one of the following options:');
        $this->io->listing([
            '--all                           Purge all cached content',
            '--url=https://example.com/path  Purge specific URL',
            '--tag=products                  Purge content with cache tag',
            '--batch-file=urls.txt           Batch purge from file',
            'target                          Auto-detect target type (URL or tag)'
        ]);

        return self::FAILURE;
    }

    private function purgeAll(bool $dryRun, bool $force): int
    {
        $this->io->section('🗑️ Purging All Cached Content');

        if (!$force && !$dryRun) {
            $this->io->warning('This will purge ALL cached content from the edge cache.');
            if (!$this->io->confirm('Are you sure you want to continue?', false)) {
                $this->io->text('Operation cancelled.');
                return self::SUCCESS;
            }
        }

        if ($dryRun) {
            $this->io->text('Would purge: All cached content');
            return self::SUCCESS;
        }

        $this->io->text('Purging all cached content...');
        $progressBar = $this->io->createProgressBar();
        $progressBar->setMessage('Initializing purge...');
        $progressBar->start();

        try {
            $result = $this->edgeCacheService->purgeAll();

            $progressBar->setMessage('Purge completed');
            $progressBar->finish();
            $this->io->newLine(2);

            if ($result) {
                $this->io->success('✅ Successfully purged all cached content');

                // Verification not supported by EdgeCacheService
            } else {
                $this->io->error('❌ Failed to purge all cached content');
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $progressBar->clear();
            $this->io->error('Error during purge: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function purgeUrl(string $url, bool $dryRun): int
    {
        $this->io->section("🌐 Purging URL: {$url}");

        // Validate URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->io->error('Invalid URL format');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->io->text("Would purge URL: {$url}");
            return self::SUCCESS;
        }

        try {
            $result = $this->edgeCacheService->purgeUrl($url);

            if ($result) {
                $this->io->success('✅ Successfully purged URL from cache');

                // Verification not supported by EdgeCacheService
            } else {
                $this->io->error('❌ Failed to purge URL from cache');
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->io->error('Error purging URL: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function purgeTag(string $tag, bool $dryRun): int
    {
        $this->io->section("🏷️ Purging Cache Tag: {$tag}");

        if ($dryRun) {
            $this->io->text("Would purge cache tag: {$tag}");
            return self::SUCCESS;
        }

        try {
            $result = $this->edgeCacheService->purgeByTag($tag);

            if ($result) {
                $this->io->success('✅ Successfully purged content with cache tag');

                // Verification not supported by EdgeCacheService
            } else {
                $this->io->error('❌ Failed to purge content with cache tag');
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->io->error('Error purging by tag: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // purgePattern method removed - not supported by EdgeCacheService

    private function purgeBatch(string $batchFile, bool $dryRun): int
    {
        $this->io->section("📄 Batch Purge from File: {$batchFile}");

        if (!file_exists($batchFile)) {
            $this->io->error("Batch file not found: {$batchFile}");
            return self::FAILURE;
        }

        $lines = file($batchFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) {
            $this->io->warning('Batch file is empty');
            return self::SUCCESS;
        }

        $this->io->text("Found " . count($lines) . " entries to purge");

        if ($dryRun) {
            $this->io->text('Would purge:');
            foreach (array_slice($lines, 0, 10) as $line) {
                $this->io->text("  - {$line}");
            }
            if (count($lines) > 10) {
                $this->io->text("  ... and " . (count($lines) - 10) . " more");
            }
            return self::SUCCESS;
        }

        $progressBar = $this->io->createProgressBar(count($lines));
        $progressBar->start();

        $successful = 0;
        $failed = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) {
                continue; // Skip comments and empty lines
            }

            $progressBar->setMessage("Purging: {$line}");

            try {
                $result = $this->purgeTarget($line, false);
                if ($result === self::SUCCESS) {
                    $successful++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
                if ($this->io->isVerbose()) {
                    $this->io->text("Failed to purge {$line}: " . $e->getMessage());
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->io->newLine(2);

        $this->io->text("Batch purge completed:");
        $this->io->text("  ✅ Successful: {$successful}");
        $this->io->text("  ❌ Failed: {$failed}");

        // Verification not supported by EdgeCacheService

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function purgeTarget(string $target, bool $dryRun): int
    {
        // Auto-detect target type
        if (filter_var($target, FILTER_VALIDATE_URL)) {
            return $this->purgeUrl($target, $dryRun);
        } else {
            // Assume it's a cache tag (pattern matching not supported)
            return $this->purgeTag($target, $dryRun);
        }
    }

    // verifyPurge method removed - not supported by EdgeCacheService

    private function displayCacheStats(string $phase): void
    {
        $this->io->section("📊 Cache Statistics ({$phase} purge)");

        try {
            // Implementation would depend on edge cache service stats capabilities
            if (method_exists($this->edgeCacheService, 'getStats')) {
                $stats = $this->edgeCacheService->getStats();

                $rows = [
                    ['Metric', 'Value'],
                    ['Cache Entries', number_format($stats['entries'] ?? 0)],
                    ['Cache Size', $this->formatBytes($stats['size'] ?? 0)],
                    ['Hit Rate', ($stats['hit_rate'] ?? 0) . '%'],
                    ['Provider', $stats['provider'] ?? 'Unknown'],
                ];

                if (isset($stats['regions'])) {
                    $rows[] = ['Regions', implode(', ', $stats['regions'])];
                }

                $this->io->table($rows[0], array_slice($rows, 1));
            } else {
                $this->io->text('Cache statistics not available for current provider');
            }
        } catch (\Exception $e) {
            $this->io->warning('Failed to retrieve cache statistics: ' . $e->getMessage());
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1024 ** $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
