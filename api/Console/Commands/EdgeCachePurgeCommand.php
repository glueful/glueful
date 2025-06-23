<?php

declare(strict_types=1);

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Cache\EdgeCacheService;

/**
 * Cache Management Command
 *
 * Provides CLI interface for managing application cache:
 * - Clear cache
 * - View cache stats
 * - Inspect cached items
 * - Manage TTL
 *
 * @package Glueful\Console\Commands
 */
class EdgeCachePurgeCommand extends Command
{
    /**
     * The name of the command
     */
    protected string $name = 'edgecache:purge';

    /**
     * The description of the command
     */
    protected string $description = 'Manage edge cache';

    /**
     * The command syntax
     */
    protected string $syntax = 'edgecache:purge [options]';

    /**
     * Command options
     */
    protected array $options = [
        'url' => 'Purge a specific URL from cache',
        'tag' => 'Purge content with specific cache tag',
        'all' => 'Purge all cached content',
    ];

    /**
     * Get the command name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get Command Description
     *
     * @return string Brief description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Edge Cache Service instance
     *
     * @var EdgeCacheService
     */
    private EdgeCacheService $edgeCacheService;

    /**
     * Create a new command instance
     *
     * @param EdgeCacheService|null $edgeCacheService Edge cache service
     */
    public function __construct(?EdgeCacheService $edgeCacheService = null)
    {
        $this->edgeCacheService = $edgeCacheService ?? new EdgeCacheService();
    }

    /**
     * Execute the command
     *
     * @param array $args Command arguments
     * @return int Command exit code
     */
    public function execute(array $args = []): int
    {
        if (empty($args) || in_array($args[0], ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        // Parse options
        $this->parseOptions($args);

        // Show help if requested
        if (in_array('-h', $args) || in_array('--help', $args)) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        return $this->handle();
    }

    /**
     * Handle command execution
     *
     * @return int Command exit code
     */
    private function handle(): int
    {
        // Check if edge caching is enabled
        if (!$this->edgeCacheService->isEnabled()) {
            $this->error('Edge caching is not enabled. Enable it in your configuration first.');
            return self::FAILURE;
        }

        // Display provider information
        $provider = $this->edgeCacheService->getProvider();
        $this->info("Edge cache provider: {$provider}");

        // Check which purge action to perform
        if ($this->option('all')) {
            return $this->purgeAll();
        }

        if ($url = $this->option('url')) {
            return $this->purgeUrl($url);
        }

        if ($tag = $this->option('tag')) {
            return $this->purgeTag($tag);
        }

        // If no option provided, display help
        $this->warning('No purge option specified. Please use one of the following options:');
        $this->line('  --url=https://example.com/path    Purge specific URL from cache');
        $this->line('  --tag=products                    Purge content with specific cache tag');
        $this->line('  --all                             Purge all cached content');

        return self::FAILURE;
    }

    /**
     * Purge all cached content
     *
     * @return int Command exit code
     */
    private function purgeAll(): int
    {
        $this->info('Purging all cached content...');

        try {
            $result = $this->edgeCacheService->purgeAll();

            if ($result) {
                $this->success('Successfully purged all cached content');
                return self::SUCCESS;
            }

            $this->error('Failed to purge all cached content');
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Error while purging cache: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Purge specific URL from cache
     *
     * @param string $url URL to purge
     * @return int Command exit code
     */
    private function purgeUrl(string $url): int
    {
        $this->info("Purging URL from cache: {$url}");

        try {
            $result = $this->edgeCacheService->purgeUrl($url);

            if ($result) {
                $this->success('Successfully purged URL from cache');
                return self::SUCCESS;
            }

            $this->error('Failed to purge URL from cache');
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Error while purging URL: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Purge content with specific cache tag
     *
     * @param string $tag Cache tag to purge
     * @return int Command exit code
     */
    private function purgeTag(string $tag): int
    {
        $this->info("Purging content with cache tag: {$tag}");

        try {
            $result = $this->edgeCacheService->purgeByTag($tag);

            if ($result) {
                $this->success('Successfully purged content with cache tag');
                return self::SUCCESS;
            }

            $this->error('Failed to purge content with cache tag');
            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Error while purging by tag: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Parse command options
     *
     * @param array $args Command arguments
     * @return void
     */
    private function parseOptions(array $args): void
    {
        $this->options = [];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];

            // Skip if it's not an option
            if (!str_starts_with($arg, '-')) {
                continue;
            }

            // Handle long options (--option=value)
            if (str_starts_with($arg, '--')) {
                $parts = explode('=', $arg, 2); // Limit to 2 parts
                if (count($parts) === 2) {
                    $key = ltrim($parts[0], '-');
                    $this->options[$key] = $parts[1];
                } else {
                    // Handle flag options like --all
                    $key = ltrim($arg, '-');
                    $this->options[$key] = true;
                }
            }
        }
    }

   /**
     * Get command help text
     *
     * @return string Help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Usage:
  edgecache:purge [options]

Options:
  --url=<url>           Purge a specific URL from cache
  --tag=<tag>           Purge content with specific cache tag
  --all                 Purge all cached content
  -h, --help            Show this help message

Examples:
  php glueful edgecache:purge --url=https://example.com/path
  php glueful edgecache:purge --tag=products
  php glueful edgecache:purge --all
HELP;
    }

    /**
     * Show command help
     */
    protected function showHelp(): void
    {
        $this->line($this->getHelp());
    }

     /**
     * Get option value
     *
     * @param string $key Option key
     * @return mixed Option value or null if not set
     */
    private function option(string $key)
    {
        return $this->options[$key] ?? null;
    }
}
