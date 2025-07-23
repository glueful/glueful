<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Queue;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Base Queue Command
 * Base class for all Queue-related Symfony Console commands.
 * Provides shared functionality for queue operations.
 * @package Glueful\Console\Commands\Queue
 */
abstract class BaseQueueCommand extends BaseCommand
{
    /**
     * Extract option value from arguments
     */
    protected function extractOptionValue(array $args, string $option, $default = null)
    {
        // Handle --option=value format
        foreach ($args as $arg) {
            if (str_starts_with($arg, $option . '=')) {
                return substr($arg, strlen($option) + 1);
            }
        }

        // Handle --option value format
        $index = array_search($option, $args);
        if ($index !== false && isset($args[$index + 1])) {
            $nextArg = $args[$index + 1];
            // Make sure next argument is not another option
            if (!str_starts_with($nextArg, '--')) {
                return $nextArg;
            }
        }

        return $default;
    }

    /**
     * Check if option exists in arguments
     */
    protected function hasOption(array $args, string $option): bool
    {
        return in_array($option, $args) ||
               (bool) array_filter($args, fn($arg) => str_starts_with($arg, $option . '='));
    }

    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Format duration to human readable format
     */
    protected function formatDuration(float $seconds): string
    {
        if ($seconds < 1) {
            return round($seconds * 1000) . 'ms';
        } elseif ($seconds < 60) {
            return round($seconds, 2) . 's';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $seconds = $seconds % 60;
            return $minutes . 'm ' . round($seconds) . 's';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . 'h ' . $minutes . 'm';
        }
    }

    /**
     * Clear terminal screen
     */
    protected function clearScreen(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            system('cls');
        } else {
            system('clear');
        }
    }

    /**
     * Parse queue names from comma-separated string
     */
    protected function parseQueues(?string $queues): array
    {
        if ($queues === null) {
            return ['default'];
        }

        return array_map('trim', explode(',', $queues));
    }

    /**
     * Display health status with icon
     */
    protected function displayHealthStatus(bool $healthy, string $label): void
    {
        $icon = $healthy ? '✅' : '❌';
        if ($healthy) {
            $this->info("{$icon} {$label}");
        } else {
            $this->error("{$icon} {$label}");
        }
    }

    /**
     * Confirm user action using Symfony Console helper
     */
    protected function confirm(string $question, bool $default = false): bool
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $confirmQuestion = new ConfirmationQuestion($question . ' (y/N) ', $default);

        return $helper->ask($this->input, $this->output, $confirmQuestion);
    }

    /**
     * Format number with thousand separators
     */
    protected function formatNumber($number, int $decimals = 0): string
    {
        return number_format($number, $decimals);
    }

    /**
     * Get status color based on value
     */
    protected function getStatusColor(string $status): string
    {
        return match (strtolower($status)) {
            'running', 'active', 'healthy', 'success' => 'info',
            'idle', 'waiting', 'pending' => 'comment',
            'failed', 'error', 'unhealthy' => 'error',
            'warning', 'degraded' => 'warning',
            default => 'line'
        };
    }

    /**
     * Display JSON output with proper formatting
     */
    protected function displayJson($data, bool $pretty = true): void
    {
        $flags = $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : 0;
        $this->output->writeln(json_encode($data, $flags));
    }

    /**
     * Get option value for args parsing
     */
    protected function getOptionValue(array $args, string $option, $default = null)
    {
        return $this->extractOptionValue($args, $option, $default);
    }
}
