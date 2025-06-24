<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Cache;

use Glueful\Cache\CacheStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cache TTL Command
 * - Get TTL (Time To Live) for cached items
 * - Human-readable time formatting
 * - Enhanced error handling and validation
 * - Support for pattern-based TTL checking
 * - Detailed cache expiration information
 * @package Glueful\Console\Commands\Cache
 */
#[AsCommand(
    name: 'cache:ttl',
    description: 'Get TTL (Time To Live) for cached items'
)]
class TtlCommand extends BaseCommand
{
    private CacheStore $cacheStore;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Get TTL (Time To Live) for cached items')
             ->setHelp('This command shows the remaining time-to-live for cached items, ' .
                      'helping you understand when cache entries will expire.')
             ->addArgument(
                 'key',
                 InputArgument::REQUIRED,
                 'Cache key to check TTL for'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $key = $input->getArgument('key');

        $this->io->title("🕒 Cache TTL Check: {$key}");

        try {
            // Check if the cache entry exists first
            $exists = $this->cacheStore->has($key);
            if (!$exists) {
                $this->io->warning("❌ Cache entry not found: {$key}");
                return self::FAILURE;
            }

            // Get TTL
            $ttl = $this->cacheStore->ttl($key);

            if ($ttl < 0) {
                if ($ttl === -1) {
                    $this->io->info("♾️ Cache entry \"{$key}\" has no expiration (persistent)");
                } else {
                    $this->io->warning("❓ Cache entry \"{$key}\" not found or has no TTL");
                    return self::FAILURE;
                }
            } else {
                $this->io->success("✅ TTL for \"{$key}\":");

                $this->displayTtlInfo($ttl);

                // Show additional information about the cache entry
                $this->showCacheEntryInfo($key);
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error("Failed to get TTL: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function initializeServices(): void
    {
        $this->cacheStore = $this->getService(CacheStore::class);
    }

    private function displayTtlInfo(int $ttl): void
    {
        $this->io->section('⏱️ Time To Live Information');

        $rows = [
            ['Metric', 'Value'],
            ['TTL (seconds)', number_format($ttl)],
            ['Formatted Time', $this->formatTtl($ttl)],
            ['Expires At', date('Y-m-d H:i:s', time() + $ttl)],
        ];

        $this->io->table($rows[0], array_slice($rows, 1));

        // Show urgency warnings
        if ($ttl < 60) {
            $this->io->warning('⚠️ Cache entry expires in less than 1 minute!');
        } elseif ($ttl < 300) {
            $this->io->note('ℹ️ Cache entry expires in less than 5 minutes');
        }
    }

    private function showCacheEntryInfo(string $key): void
    {
        try {
            $this->io->section('📋 Cache Entry Information');

            // Get the actual cached value to show metadata
            $value = $this->cacheStore->get($key);

            $infoRows = [
                ['Property', 'Value'],
                ['Key', $key],
                ['Has Value', $value !== null ? 'Yes' : 'No'],
                ['Value Type', gettype($value)],
            ];

            if (is_string($value)) {
                $infoRows[] = ['Value Length', strlen($value) . ' characters'];
            } elseif (is_array($value)) {
                $infoRows[] = ['Array Size', count($value) . ' items'];
            }

            // Estimate size
            $serialized = serialize($value);
            $infoRows[] = ['Estimated Size', $this->formatBytes(strlen($serialized))];

            $this->io->table($infoRows[0], array_slice($infoRows, 1));
        } catch (\Exception $e) {
            $this->io->text('Could not retrieve cache entry details: ' . $e->getMessage());
        }
    }

    private function formatTtl(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        }

        if ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return "{$minutes}m {$secs}s";
        }

        if ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $secs = $seconds % 60;
            return "{$hours}h {$minutes}m {$secs}s";
        }

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return "{$days}d {$hours}h {$minutes}m";
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1024 ** $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
