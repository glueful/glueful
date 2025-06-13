<?php

namespace Glueful\Console\Commands\Queue\Traits;

/**
 * Queue Command Helpers Trait
 *
 * Provides common functionality shared across queue commands.
 * Extracted to reduce code duplication and improve maintainability.
 *
 * @package Glueful\Console\Commands\Queue\Traits
 */
trait QueueCommandHelpers
{
    /**
     * Extract option value from arguments
     *
     * @param array $args Arguments array
     * @param string $option Option name
     * @param mixed $default Default value
     * @return mixed Option value
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
     *
     * @param array $args Arguments array
     * @param string $option Option name
     * @return bool True if option exists
     */
    protected function hasOption(array $args, string $option): bool
    {
        return in_array($option, $args) ||
               array_filter($args, fn($arg) => str_starts_with($arg, $option . '='));
    }

    /**
     * Format bytes to human readable format
     *
     * @param int $bytes Number of bytes
     * @return string Formatted string
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
     *
     * @param float $seconds Duration in seconds
     * @return string Formatted duration
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
     *
     * @return void
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
     * Display table header
     *
     * @param array $headers Column headers
     * @param array $widths Column widths
     * @return void
     */
    protected function displayTableHeader(array $headers, array $widths): void
    {
        $format = '';
        foreach ($widths as $width) {
            $format .= "%-{$width}s ";
        }

        $this->line(vsprintf(trim($format), $headers));
        $this->line(str_repeat('─', array_sum($widths) + count($widths) - 1));
    }

    /**
     * Display table row
     *
     * @param array $data Row data
     * @param array $widths Column widths
     * @return void
     */
    protected function displayTableRow(array $data, array $widths): void
    {
        $format = '';
        foreach ($widths as $i => $width) {
            $format .= "%-{$width}s ";
            // Truncate data if too long
            if (isset($data[$i]) && strlen($data[$i]) > $width) {
                $data[$i] = substr($data[$i], 0, $width - 3) . '...';
            }
        }

        $this->line(vsprintf(trim($format), $data));
    }

    /**
     * Parse queue names from comma-separated string
     *
     * @param string|null $queues Queue string
     * @return array Queue names
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
     *
     * @param bool $healthy Health status
     * @param string $label Label text
     * @return void
     */
    protected function displayHealthStatus(bool $healthy, string $label): void
    {
        $icon = $healthy ? '✅' : '❌';
        $color = $healthy ? 'info' : 'error';

        $this->$color("{$icon} {$label}");
    }

    /**
     * Confirm user action
     *
     * @param string $question Question to ask
     * @param bool $default Default answer
     * @return bool User confirmation
     */
    protected function confirm(string $question, bool $default = false): bool
    {
        $defaultText = $default ? 'yes' : 'no';
        $this->line("{$question} (yes/no) [{$defaultText}]: ");

        $answer = trim(fgets(STDIN));

        if (empty($answer)) {
            return $default;
        }

        return in_array(strtolower($answer), ['y', 'yes']);
    }

    /**
     * Display progress bar
     *
     * @param int $current Current value
     * @param int $total Total value
     * @param int $width Bar width
     * @return void
     */
    protected function displayProgressBar(int $current, int $total, int $width = 40): void
    {
        if ($total === 0) {
            return;
        }

        $percentage = ($current / $total) * 100;
        $filled = (int) (($current / $total) * $width);

        $bar = str_repeat('█', $filled) . str_repeat('░', $width - $filled);

        $this->line(sprintf("\r[%s] %d%% (%d/%d)", $bar, $percentage, $current, $total));
    }

    /**
     * Format number with thousand separators
     *
     * @param int|float $number Number to format
     * @param int $decimals Decimal places
     * @return string Formatted number
     */
    protected function formatNumber($number, int $decimals = 0): string
    {
        return number_format($number, $decimals);
    }

    /**
     * Get terminal width
     *
     * @return int Terminal width
     */
    protected function getTerminalWidth(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return 80; // Default for Windows
        }

        $width = (int) exec('tput cols');
        return $width ?: 80;
    }

    /**
     * Truncate string to fit terminal width
     *
     * @param string $string String to truncate
     * @param int $maxWidth Maximum width
     * @param string $suffix Suffix for truncated strings
     * @return string Truncated string
     */
    protected function truncateString(string $string, int $maxWidth, string $suffix = '...'): string
    {
        if (strlen($string) <= $maxWidth) {
            return $string;
        }

        $suffixLength = strlen($suffix);
        if ($maxWidth < $suffixLength) {
            return substr($string, 0, $maxWidth);
        }

        return substr($string, 0, $maxWidth - $suffixLength) . $suffix;
    }

    /**
     * Display JSON output with proper formatting
     *
     * @param mixed $data Data to display
     * @param bool $pretty Pretty print
     * @return void
     */
    protected function displayJson($data, bool $pretty = true): void
    {
        $flags = $pretty ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : 0;
        echo json_encode($data, $flags) . "\n";
    }

    /**
     * Get status color based on value
     *
     * @param string $status Status value
     * @return string Color method name
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
}