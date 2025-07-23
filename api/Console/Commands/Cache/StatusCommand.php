<?php

namespace Glueful\Console\Commands\Cache;

use Glueful\Console\BaseCommand;
use Glueful\Cache\CacheStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cache Status Command
 * Displays comprehensive cache system status:
 * - Cache driver information
 * - Connection status
 * - Statistics and metrics
 * - Configuration details
 * @package Glueful\Console\Commands\Cache
 */
#[AsCommand(
    name: 'cache:status',
    description: 'Show cache system status and statistics'
)]
class StatusCommand extends BaseCommand
{
    private CacheStore $cacheStore;

    public function __construct()
    {
        parent::__construct();
        $this->cacheStore = $this->getService(CacheStore::class);
    }

    protected function configure(): void
    {
        $this->setDescription('Show cache system status and statistics')
             ->setHelp('This command displays comprehensive information about the cache system status, ' .
                      'configuration, and performance metrics.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->info('Cache System Status');
        $this->line('');

        try {
            // Test cache availability
            $this->cacheStore->get('__status_test__');

            $this->displayCacheInfo();
            $this->displayStatistics();
            $this->displayCapabilities();

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->displayErrorStatus($e);
            return self::FAILURE;
        }
    }

    private function displayCacheInfo(): void
    {
        $headers = ['Configuration', 'Value'];
        $rows = [
            ['Status', '<info>✓ Connected</info>'],
            ['Driver', config('cache.default', 'Unknown')],
            ['Prefix', config('cache.prefix', 'None')],
            ['Default TTL', config('cache.ttl', '3600') . ' seconds'],
        ];

        $this->table($headers, $rows);
    }

    private function displayStatistics(): void
    {
        $this->line('');
        $this->info('Statistics:');

        try {
            $stats = $this->cacheStore->getStats();

            if (empty($stats)) {
                $this->line('  No statistics available for this cache driver.');
                return;
            }

            $headers = ['Metric', 'Value'];
            $rows = [];

            foreach ($stats as $key => $value) {
                $rows[] = [ucfirst(str_replace('_', ' ', (string) $key)), $this->formatStatValue($value)];
            }

            $this->table($headers, $rows);
        } catch (\Exception $e) {
            $this->warning('  Statistics not available: ' . $e->getMessage());
        }
    }

    private function displayCapabilities(): void
    {
        $this->line('');
        $this->info('Capabilities:');

        try {
            $capabilities = $this->cacheStore->getCapabilities();

            if (empty($capabilities)) {
                $this->line('  No capability information available.');
                return;
            }

            foreach ($capabilities as $capability) {
                $this->line('  • ' . ucfirst(str_replace('_', ' ', $capability)));
            }
        } catch (\Exception $e) {
            $this->warning('  Capabilities not available: ' . $e->getMessage());
        }
    }

    private function displayErrorStatus(\Exception $e): void
    {
        $headers = ['Configuration', 'Value'];
        $rows = [
            ['Status', '<error>✗ Disconnected</error>'],
            ['Driver', config('cache.default', 'Unknown')],
            ['Error', $e->getMessage()],
        ];

        $this->table($headers, $rows);

        $this->line('');
        $this->error('Cache system is not properly configured or unavailable.');
        $this->tip('Check your cache configuration in config/cache.php');
    }

    private function formatStatValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_object($value)) {
            return get_class($value);
        }

        if (is_numeric($value)) {
            // Format large numbers with commas
            return number_format((float) $value);
        }

        return (string) $value;
    }
}
