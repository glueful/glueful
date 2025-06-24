<?php

namespace Glueful\Console\Commands\Cache;

use Glueful\Console\BaseCommand;
use Glueful\Cache\CacheStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cache Clear Command
 * Clears application cache with enhanced features:
 * - Tag-based cache clearing
 * - Confirmation prompts
 * - Progress feedback
 * - Multiple cache store support
 * @package Glueful\Console\Commands\Cache
 */
#[AsCommand(
    name: 'cache:clear',
    description: 'Clear application cache',
    aliases: ['cache:flush']
)]
class ClearCommand extends BaseCommand
{
    private CacheStore $cacheStore;

    public function __construct()
    {
        parent::__construct();
        $this->cacheStore = $this->getService(CacheStore::class);
    }

    protected function configure(): void
    {
        $this->setDescription('Clear application cache')
             ->setHelp('This command clears all cached data or specific cache tags.')
             ->addOption(
                 'tag',
                 't',
                 InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                 'Clear only specific cache tags'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force cache clearing without confirmation'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tags = $input->getOption('tag');
        $force = $input->getOption('force');

        try {
            // Test cache store availability
            $this->cacheStore->get('__test__');
        } catch (\Exception $e) {
            $this->error('Cache system is not available: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Show what will be cleared
        if (!empty($tags)) {
            $this->info(sprintf('Clearing cache for tags: %s', implode(', ', $tags)));
        } else {
            $this->info('Clearing all application cache...');
        }

        // Confirmation in production
        if (!$force && !$this->confirmProduction('clear application cache')) {
            return self::FAILURE;
        }

        // Confirm if not forced
        if (!$force && !$this->confirm('Are you sure you want to clear the cache?', false)) {
            $this->info('Cache clearing cancelled.');
            return self::SUCCESS;
        }

        try {
            if (!empty($tags)) {
                // Clear specific tags
                $this->clearCacheByTags($tags);
            } else {
                // Clear all cache
                $this->clearAllCache();
            }

            $this->success('Cache cleared successfully!');
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to clear cache: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function clearAllCache(): void
    {
        $result = $this->cacheStore->flush();

        if (!$result) {
            throw new \Exception('Cache flush operation failed');
        }

        $this->line('✓ All cache entries cleared');
    }

    private function clearCacheByTags(array $tags): void
    {
        try {
            // Check if cache store supports tagging
            if (method_exists($this->cacheStore, 'invalidateTags')) {
                $result = $this->cacheStore->invalidateTags($tags);
                if ($result) {
                    foreach ($tags as $tag) {
                        $this->line(sprintf('✓ Cache tag "%s" cleared', $tag));
                    }
                } else {
                    $this->warning('Failed to clear cache tags');
                }
            } else {
                $this->warning('Cache tagging is not supported by the current cache driver');
            }
        } catch (\Exception $e) {
            $this->error(sprintf('Failed to clear tags: %s', $e->getMessage()));
        }
    }
}
