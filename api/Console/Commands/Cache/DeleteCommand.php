<?php

namespace Glueful\Console\Commands\Cache;

use Glueful\Console\BaseCommand;
use Glueful\Cache\CacheStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cache Delete Command
 * Removes cached entries with enhanced features:
 * - Single key deletion
 * - Pattern-based deletion
 * - Confirmation prompts
 * - Batch operations
 * @package Glueful\Console\Commands\Cache
 */
#[AsCommand(
    name: 'cache:delete',
    description: 'Delete cached entries by key or pattern'
)]
class DeleteCommand extends BaseCommand
{
    private CacheStore $cacheStore;

    public function __construct()
    {
        parent::__construct();
        $this->cacheStore = $this->getService(CacheStore::class);
    }

    protected function configure(): void
    {
        $this->setDescription('Delete cached entries by key or pattern')
             ->setHelp('This command removes cached entries. Use --pattern for wildcard deletion.')
             ->addArgument(
                 'key',
                 InputArgument::REQUIRED,
                 'The cache key to delete (or pattern if using --pattern)'
             )
             ->addOption(
                 'pattern',
                 'p',
                 InputOption::VALUE_NONE,
                 'Treat key as a wildcard pattern (e.g., "user:*")'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force deletion without confirmation'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $input->getArgument('key');
        $isPattern = $input->getOption('pattern');
        $force = $input->getOption('force');

        try {
            if ($isPattern) {
                return $this->deleteByPattern($key, $force);
            } else {
                return $this->deleteSingleKey($key, $force);
            }
        } catch (\Exception $e) {
            $this->error('Failed to delete cache entry: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function deleteSingleKey(string $key, bool $force): int
    {
        // Check if key exists
        $value = $this->cacheStore->get($key);
        if ($value === null) {
            $this->warning(sprintf('Cache key "%s" does not exist.', $key));
            return self::SUCCESS;
        }

        // Confirm deletion if not forced
        if (!$force && !$this->confirm(sprintf('Delete cache key "%s"?', $key), false)) {
            $this->info('Deletion cancelled.');
            return self::SUCCESS;
        }

        // Delete the key
        $result = $this->cacheStore->delete($key);

        if ($result) {
            $this->success(sprintf('Cache key "%s" deleted successfully.', $key));
            return self::SUCCESS;
        } else {
            $this->error(sprintf('Failed to delete cache key "%s".', $key));
            return self::FAILURE;
        }
    }

    private function deleteByPattern(string $pattern, bool $force): int
    {
        // Check if pattern deletion is supported
        if (!method_exists($this->cacheStore, 'deletePattern')) {
            $this->error('Pattern deletion is not supported by the current cache driver.');
            $this->tip('Try deleting individual keys instead.');
            return self::FAILURE;
        }

        // Show warning for pattern deletion
        $this->warning(sprintf('About to delete all cache keys matching pattern: "%s"', $pattern));

        if (!$force) {
            $this->line('This operation cannot be undone.');
            if (!$this->confirm('Are you sure you want to continue?', false)) {
                $this->info('Pattern deletion cancelled.');
                return self::SUCCESS;
            }
        }

        try {
            // Perform pattern deletion
            $deletedCount = $this->cacheStore->deletePattern($pattern);

            if ($deletedCount > 0) {
                $this->success(sprintf('Deleted %d cache entries matching pattern "%s".', $deletedCount, $pattern));
            } else {
                $this->info(sprintf('No cache entries found matching pattern "%s".', $pattern));
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Pattern deletion failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
