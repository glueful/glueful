<?php

namespace Glueful\Console\Commands\Cache;

use Glueful\Console\BaseCommand;
use Glueful\Cache\CacheStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cache Get Command
 * Retrieves and displays cached values:
 * - Key-based value retrieval
 * - Formatted output display
 * - Type detection and formatting
 * - TTL information
 * @package Glueful\Console\Commands\Cache
 */
#[AsCommand(
    name: 'cache:get',
    description: 'Get a cached value by key'
)]
class GetCommand extends BaseCommand
{
    private CacheStore $cacheStore;

    public function __construct()
    {
        parent::__construct();
        $this->cacheStore = $this->getService(CacheStore::class);
    }

    protected function configure(): void
    {
        $this->setDescription('Get a cached value by key')
             ->setHelp('This command retrieves and displays a cached value for the specified key.')
             ->addArgument(
                 'key',
                 InputArgument::REQUIRED,
                 'The cache key to retrieve'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $input->getArgument('key');

        try {
            $value = $this->cacheStore->get($key);

            if ($value === null) {
                $this->warning(sprintf('No cache entry found for key: "%s"', $key));
                return self::SUCCESS;
            }

            $this->displayCacheEntry($key, $value);
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to retrieve cache entry: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function displayCacheEntry(string $key, $value): void
    {
        $this->info(sprintf('Cache entry for key: "%s"', $key));
        $this->line('');

        // Display value information
        $headers = ['Property', 'Value'];
        $rows = [
            ['Key', $key],
            ['Type', $this->getValueType($value)],
            ['Size', $this->getValueSize($value)],
        ];

        $this->table($headers, $rows);

        // Display the actual value
        $this->line('');
        $this->info('Value:');
        $this->displayValue($value);
    }

    private function displayValue($value): void
    {
        if (is_string($value)) {
            $this->line($value);
        } elseif (is_array($value) || is_object($value)) {
            $this->line(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif (is_bool($value)) {
            $this->line($value ? 'true' : 'false');
        } elseif (is_null($value)) {
            $this->line('null');
        } else {
            $this->line((string) $value);
        }
    }

    private function getValueType($value): string
    {
        $type = gettype($value);

        if ($type === 'object') {
            return 'object (' . get_class($value) . ')';
        }

        return $type;
    }

    private function getValueSize($value): string
    {
        if (is_string($value)) {
            return strlen($value) . ' characters';
        }

        if (is_array($value)) {
            return count($value) . ' items';
        }

        // Approximate serialized size
        $serialized = serialize($value);
        return strlen($serialized) . ' bytes (serialized)';
    }
}
