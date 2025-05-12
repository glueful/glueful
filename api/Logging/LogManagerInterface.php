<?php

namespace Glueful\Logging;

use Psr\Log\LoggerInterface;

interface LogManagerInterface
{
    /**
     * Get a logger for a specific channel
     *
     * @param string $channel
     * @return LoggerInterface
     */
    public function getLogger(string $channel): LoggerInterface;

    /**
     * Log an error message
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function error(string $message, array $context = []): void;

        /**
     * Get singleton instance
     *
     * @return self
     */
    public static function getInstance(): self;
}
