<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Cache;

use Glueful\Cache\CacheStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cache Expire Command
 * - Set new TTL/expiration for cached items
 * - Support for human-readable time formats
 * - Bulk expiration operations
 * - Verification and confirmation options
 * - Enhanced error handling and validation
 * @package Glueful\Console\Commands\Cache
 */
#[AsCommand(
    name: 'cache:expire',
    description: 'Set new TTL/expiration for cached items'
)]
class ExpireCommand extends BaseCommand
{
    private CacheStore $cacheStore;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Set new TTL/expiration for cached items')
             ->setHelp('This command allows you to update the expiration time for cached items, ' .
                      'effectively extending or reducing their time-to-live.')
             ->addArgument(
                 'key',
                 InputArgument::REQUIRED,
                 'Cache key to set expiration for'
             )
             ->addArgument(
                 'seconds',
                 InputArgument::REQUIRED,
                 'Time until expiration in seconds (or use --human-time for readable format)'
             )
             ->addOption(
                 'human-time',
                 null,
                 InputOption::VALUE_NONE,
                 'Parse seconds argument as human-readable time (e.g., 1h30m, 2d, 30m)'
             )
             ->addOption(
                 'verify',
                 'v',
                 InputOption::VALUE_NONE,
                 'Verify the operation by checking TTL after setting'
             )
             ->addOption(
                 'force',
                 'f',
                 InputOption::VALUE_NONE,
                 'Force operation without confirmation for destructive actions'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initializeServices();

        $key = $input->getArgument('key');
        $secondsInput = $input->getArgument('seconds');
        $humanTime = $input->getOption('human-time');
        $verify = $input->getOption('verify');
        $force = $input->getOption('force');

        $this->io->title("⏰ Setting Cache Expiration: {$key}");

        try {
            // Check if the cache entry exists
            if (!$this->cacheStore->has($key)) {
                $this->io->error("❌ Cache entry not found: {$key}");
                return self::FAILURE;
            }

            // Parse time input
            $seconds = $humanTime
                ? $this->parseHumanTime($secondsInput)
                : (int) $secondsInput;

            if ($seconds <= 0) {
                $this->io->error('❌ Invalid time specification. Seconds must be positive.');
                return self::FAILURE;
            }

            // Show current TTL before change
            $currentTtl = $this->cacheStore->ttl($key);
            $this->displayCurrentTtl($currentTtl);

            // Show what will be changed
            $this->displayProposedChange($seconds, $currentTtl);

            // Confirm if not forced and it's a significant change
            if (!$force && $this->isSignificantChange($currentTtl, $seconds)) {
                if (!$this->io->confirm('Continue with expiration change?', true)) {
                    $this->io->text('Operation cancelled.');
                    return self::SUCCESS;
                }
            }

            // Set the new expiration
            $result = $this->cacheStore->expire($key, $seconds);

            if ($result) {
                $this->io->success("✅ Expiration set for \"{$key}\"");
                $this->io->text("New TTL: " . $this->formatTtl($seconds));
                $this->io->text("Expires at: " . date('Y-m-d H:i:s', time() + $seconds));

                // Verify if requested
                if ($verify) {
                    $this->verifyExpiration($key, $seconds);
                }
            } else {
                $this->io->error("❌ Failed to set expiration for \"{$key}\"");
                $this->io->text('This might happen if the cache entry was deleted during the operation.');
                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->io->error("Failed to set expiration: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function initializeServices(): void
    {
        $this->cacheStore = $this->getService(CacheStore::class);
    }

    private function parseHumanTime(string $timeString): int
    {
        $timeString = strtolower(trim($timeString));
        $totalSeconds = 0;

        // Parse patterns like 1h30m, 2d, 30m, 1h, etc.
        if (preg_match_all('/(\d+)([dhms])/', $timeString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $value = (int) $match[1];
                $unit = $match[2];

                switch ($unit) {
                    case 'd':
                        $totalSeconds += $value * 86400; // days
                        break;
                    case 'h':
                        $totalSeconds += $value * 3600; // hours
                        break;
                    case 'm':
                        $totalSeconds += $value * 60; // minutes
                        break;
                    case 's':
                        $totalSeconds += $value; // seconds
                        break;
                }
            }
        } else {
            // If no pattern matched, try to parse as pure number
            $totalSeconds = (int) $timeString;
        }

        return $totalSeconds;
    }

    private function displayCurrentTtl(int $currentTtl): void
    {
        $this->io->section('📊 Current Cache Information');

        if ($currentTtl < 0) {
            if ($currentTtl === -1) {
                $this->io->text('Current TTL: ♾️ No expiration (persistent)');
            } else {
                $this->io->text('Current TTL: ❓ Unknown or expired');
            }
        } else {
            $this->io->text('Current TTL: ' . $this->formatTtl($currentTtl));
            $this->io->text('Current expiry: ' . date('Y-m-d H:i:s', time() + $currentTtl));
        }
    }

    private function displayProposedChange(int $newSeconds, int $currentTtl): void
    {
        $this->io->section('🔄 Proposed Change');

        $rows = [
            ['Property', 'Current', 'New'],
            ['TTL (seconds)', $currentTtl > 0 ? number_format($currentTtl) : 'N/A', number_format($newSeconds)],
            ['Formatted Time', $currentTtl > 0 ? $this->formatTtl($currentTtl) : 'N/A', $this->formatTtl($newSeconds)],
            [
                'Expiry Time',
                $currentTtl > 0 ? date('H:i:s', time() + $currentTtl) : 'N/A',
                date('H:i:s', time() + $newSeconds)
            ],
        ];

        $this->io->table($rows[0], array_slice($rows, 1));

        // Show change impact
        if ($currentTtl > 0) {
            $change = $newSeconds - $currentTtl;
            if ($change > 0) {
                $this->io->text("📈 Extending TTL by " . $this->formatTtl($change));
            } else {
                $this->io->text("📉 Reducing TTL by " . $this->formatTtl(abs($change)));
            }
        }
    }

    private function isSignificantChange(int $currentTtl, int $newTtl): bool
    {
        if ($currentTtl <= 0) {
            return true; // Any change to persistent cache is significant
        }

        $percentChange = abs(($newTtl - $currentTtl) / $currentTtl) * 100;
        return $percentChange > 50; // Consider >50% change as significant
    }

    private function verifyExpiration(string $key, int $expectedSeconds): void
    {
        $this->io->section('🔍 Verification');

        // Give a small buffer for execution time
        $actualTtl = $this->cacheStore->ttl($key);
        $tolerance = 5; // 5 seconds tolerance

        if (
            $actualTtl >= ($expectedSeconds - $tolerance) &&
            $actualTtl <= $expectedSeconds
        ) {
            $this->io->success('✅ Verification passed: TTL set correctly');
            $this->io->text("Actual TTL: " . $this->formatTtl($actualTtl));
        } else {
            $this->io->warning('⚠️ Verification warning: TTL differs from expected');
            $this->io->text("Expected: " . $this->formatTtl($expectedSeconds));
            $this->io->text("Actual: " . $this->formatTtl($actualTtl));
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
}
