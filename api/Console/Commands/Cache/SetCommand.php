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
 * Cache Set Command
 * Stores values in cache with enhanced features:
 * - TTL configuration
 * - Value type detection
 * - JSON value support
 * - Confirmation and validation
 * @package Glueful\Console\Commands\Cache
 */
#[AsCommand(
    name: 'cache:set',
    description: 'Set a cache value with key and optional TTL'
)]
class SetCommand extends BaseCommand
{
    private CacheStore $cacheStore;

    public function __construct()
    {
        parent::__construct();
        $this->cacheStore = $this->getService(CacheStore::class);
    }

    protected function configure(): void
    {
        $this->setDescription('Set a cache value with key and optional TTL')
             ->setHelp('This command stores a value in cache with the specified key and optional TTL.')
             ->addArgument(
                 'key',
                 InputArgument::REQUIRED,
                 'The cache key to set'
             )
             ->addArgument(
                 'value',
                 InputArgument::REQUIRED,
                 'The value to cache (use --json for JSON values)'
             )
             ->addOption(
                 'ttl',
                 't',
                 InputOption::VALUE_REQUIRED,
                 'Time to live in seconds',
                 3600
             )
             ->addOption(
                 'json',
                 'j',
                 InputOption::VALUE_NONE,
                 'Parse value as JSON'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $key = $input->getArgument('key');
        $rawValue = $input->getArgument('value');
        $ttl = (int) $input->getOption('ttl');
        $isJson = $input->getOption('json');

        // Validate TTL
        if ($ttl < 0) {
            $this->error('TTL must be a positive number or zero (for no expiration).');
            return self::FAILURE;
        }

        // Process value
        try {
            $value = $this->processValue($rawValue, $isJson);
        } catch (\Exception $e) {
            $this->error('Invalid value format: ' . $e->getMessage());
            return self::FAILURE;
        }

        try {
            // Set the cache value
            $result = $this->cacheStore->set($key, $value, $ttl);

            if ($result) {
                $this->success(sprintf('Cache entry set successfully!'));
                $this->displaySetInfo($key, $value, $ttl);
                return self::SUCCESS;
            } else {
                $this->error('Failed to set cache entry.');
                return self::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error('Failed to set cache entry: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function processValue(string $rawValue, bool $isJson)
    {
        if ($isJson) {
            $decoded = json_decode($rawValue, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON: ' . json_last_error_msg());
            }
            return $decoded;
        }

        // Auto-detect special values
        $lower = strtolower($rawValue);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if (is_numeric($rawValue)) {
            return str_contains($rawValue, '.') ? (float) $rawValue : (int) $rawValue;
        }

        return $rawValue;
    }

    private function displaySetInfo(string $key, $value, int $ttl): void
    {
        $this->line('');

        $headers = ['Property', 'Value'];
        $rows = [
            ['Key', $key],
            ['Type', gettype($value)],
            ['TTL', $ttl === 0 ? 'No expiration' : $ttl . ' seconds'],
            ['Expires', $ttl === 0 ? 'Never' : date('Y-m-d H:i:s', time() + $ttl)],
        ];

        $this->table($headers, $rows);

        $this->line('');
        $this->info('Stored value:');
        if (is_array($value) || is_object($value)) {
            $this->line(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line((string) $value);
        }
    }
}
