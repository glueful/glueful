<?php

declare(strict_types=1);

namespace Glueful\Console\Commands;

use Glueful\Console\Command;
use Glueful\Cache\CacheEngine;
use Glueful\DI\Interfaces\ContainerInterface;

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
class CacheCommand extends Command
{
    /**
     * The name of the command
     */
    protected string $name = 'cache';

    /**
     * The description of the command
     */
    protected string $description = 'Manage application cache';

    /**
     * The command syntax
     */
    protected string $syntax = 'cache [action] [options]';

    /**
     * Command options
     */
    protected array $options = [
        'clear'    => 'Clear all cached data',
        'flush'    => 'Alias for clear',
        'status'   => 'Show cache status',
        'get'      => 'Get cached item by key',
        'set'      => 'Set cache item with key, value and TTL',
        'delete'   => 'Delete cached item by key',
        'ttl'      => 'Get TTL for cached item',
        'expire'   => 'Set new TTL for cached item'
    ];

    /** @var ContainerInterface|null DI Container */
    protected ?ContainerInterface $container;

    /**
     * Constructor
     *
     * @param ContainerInterface|null $container DI Container instance
     */
    public function __construct(?ContainerInterface $container = null)
    {
        $this->container = $container ?? $this->getDefaultContainer();

        // Initialize the cache engine
        CacheEngine::initialize();
    }

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
     * Execute the command
     *
     * @param array $args Command arguments
     * @param array $options Command options
     * @return int Exit code
     */
    public function execute(array $args = [], array $options = []): int
    {
        if (empty($args) || in_array($args[0], ['-h', '--help', 'help'])) {
            $this->info($this->getHelp());
            return Command::SUCCESS;
        }

        if (!CacheEngine::isEnabled()) {
            $this->error("Cache system is not enabled");
            return Command::FAILURE;
        }

        $action = $args[0];

        if (!array_key_exists($action, $this->options)) {
            $this->error("Unknown action: $action");
            $this->showHelp();
            return Command::FAILURE;
        }

        try {
            switch ($action) {
                case 'clear':
                case 'flush':
                    $this->clearCache();
                    break;

                case 'status':
                    $this->showStatus();
                    break;

                case 'get':
                    if (empty($args[1])) {
                        $this->error('Key is required for get action');
                        return Command::INVALID;
                    }
                    $this->getItem($args[1]);
                    break;

                case 'set':
                    if (count($args) < 3) {
                        $this->error('Key and value are required for set action');
                        return Command::INVALID;
                    }
                    $ttl = isset($args[3]) ? (int)$args[3] : 3600;
                    $this->setItem($args[1], $args[2], $ttl);
                    break;

                case 'delete':
                    if (empty($args[1])) {
                        $this->error('Key is required for delete action');
                        return Command::INVALID;
                    }
                    $this->deleteItem($args[1]);
                    break;

                case 'ttl':
                    if (empty($args[1])) {
                        $this->error('Key is required for ttl action');
                        return Command::INVALID;
                    }
                    $this->getTtl($args[1]);
                    break;

                case 'expire':
                    if (count($args) < 3) {
                        $this->error('Key and seconds are required for expire action');
                        return Command::INVALID;
                    }
                    $this->setExpire($args[1], (int)$args[2]);
                    break;

                default:
                    $this->error("Action not implemented: $action");
                    return Command::FAILURE;
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Command failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Clear all cache
     */
    protected function clearCache(): void
    {
        $this->info('Clearing cache...');

        $result = CacheEngine::flush();

        if ($result) {
            $this->success('Cache cleared successfully');
        } else {
            $this->error('Failed to clear cache');
        }
    }

    /**
     * Show cache status
     */
    protected function showStatus(): void
    {
        $this->info('Cache Status');
        $this->line('============');

        if (CacheEngine::isEnabled()) {
            $this->line('Status:  ' . $this->colorText('Enabled', 'green'));
            $this->line('Driver:  ' .  config('cache.default'));
            $this->line('Prefix:  ' . config('cache.prefix'));
        } else {
            $this->line('Status:  ' . $this->colorText('Disabled', 'red'));
        }
    }

    /**
     * Get cached item
     *
     * @param string $key Cache key
     */
    protected function getItem(string $key): void
    {
        $value = CacheEngine::get($key);

        if ($value === null) {
            $this->warning("No cache entry found for key: $key");
            return;
        }

        $this->info("Cache value for \"$key\":");

        if (is_array($value) || is_object($value)) {
            $this->line(json_encode($value, JSON_PRETTY_PRINT));
        } else {
            $this->line((string)$value);
        }

        // Show TTL as well
        $ttl = CacheEngine::ttl($key);
        if ($ttl > 0) {
            $this->line("\nExpires in: " . $this->formatTtl($ttl));
        }
    }

    /**
     * Set cached item
     *
     * @param string $key Cache key
     * @param string $value Cache value
     * @param int $ttl Time to live in seconds
     */
    protected function setItem(string $key, string $value, int $ttl): void
    {
        // Try to decode JSON if value looks like JSON
        if (in_array($value[0], ['{', '[']) && json_decode($value) !== null) {
            $value = json_decode($value, true);
        }

        $result = CacheEngine::set($key, $value, $ttl);

        if ($result) {
            $this->success("Cache entry \"$key\" set successfully");
            $this->line("TTL: " . $this->formatTtl($ttl));
        } else {
            $this->error("Failed to set cache entry \"$key\"");
        }
    }

    /**
     * Delete cached item
     *
     * @param string $key Cache key
     */
    protected function deleteItem(string $key): void
    {
        $result = CacheEngine::delete($key);

        if ($result) {
            $this->success("Cache entry \"$key\" deleted successfully");
        } else {
            $this->warning("Cache entry \"$key\" not found or could not be deleted");
        }
    }

    /**
     * Get TTL for cached item
     *
     * @param string $key Cache key
     */
    protected function getTtl(string $key): void
    {
        $ttl = CacheEngine::ttl($key);

        if ($ttl < 0) {
            $this->warning("Cache entry \"$key\" not found or has no TTL");
            return;
        }

        $this->info("TTL for \"$key\":");
        $this->line($this->formatTtl($ttl));
    }

    /**
     * Set expiration for cached item
     *
     * @param string $key Cache key
     * @param int $seconds Time until expiration
     */
    protected function setExpire(string $key, int $seconds): void
    {
        $result = CacheEngine::expire($key, $seconds);

        if ($result) {
            $this->success("Expiration set for \"$key\"");
            $this->line("TTL: " . $this->formatTtl($seconds));
        } else {
            $this->error("Failed to set expiration for \"$key\"");
        }
    }

    /**
     * Format TTL into human-readable format
     *
     * @param int $seconds Seconds
     * @return string Formatted time
     */
    private function formatTtl(int $seconds): string
    {
        if ($seconds < 60) {
            return "$seconds seconds";
        }

        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return "{$minutes}m {$secs}s";
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return "{$hours}h {$minutes}m {$secs}s";
    }

    /**
     * Get Command Help
     *
     * @return string Detailed help text
     */
    public function getHelp(): string
    {
        return <<<HELP
Cache Management Command
=======================

Manage the application cache system.

Usage:
  cache clear                       Clear all cached data
  cache status                      Show cache status
  cache get <key>                   Get cached item by key
  cache set <key> <value> [<ttl>]   Set cache item with key, value and optional TTL (in seconds)
  cache delete <key>                Delete cached item by key
  cache ttl <key>                   Get TTL for cached item
  cache expire <key> <seconds>      Set new TTL for cached item

Options:
  -h, --help                        Show this help message

Examples:
  php glueful cache clear
  php glueful cache get user:123
  php glueful cache set app:config '{"debug":true}' 3600
  php glueful cache expire session:token 1800
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
     * Get default container safely
     *
     * @return ContainerInterface|null
     */
    private function getDefaultContainer(): ?ContainerInterface
    {
        // Check if app() function exists (available when bootstrap is loaded)
        if (function_exists('app')) {
            try {
                return app();
            } catch (\Exception $e) {
                // Fall back to null if container is not available
                return null;
            }
        }

        return null;
    }
}
