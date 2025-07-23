<?php

declare(strict_types=1);

namespace Glueful\Database\Exceptions;

use RuntimeException;

/**
 * ConnectionPoolException
 *
 * Exception thrown for connection pool related errors including:
 * - Connection acquisition timeouts
 * - Pool exhaustion
 * - Connection creation failures
 * - Health check failures
 *
 * @package Glueful\Database\Exceptions
 */
class ConnectionPoolException extends RuntimeException
{
    /** @var array Additional context about the error */
    private array $context = [];

    /**
     * Create exception with context
     *
     * @param string $message Error message
     * @param array $context Additional error context
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = "",
        array $context = [],
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get error context
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Create timeout exception
     *
     * @param float $timeout Timeout duration
     * @param array $poolState Current pool state
     * @return self
     */
    public static function acquisitionTimeout(float $timeout, array $poolState): self
    {
        return new self(
            sprintf(
                'Connection acquisition timeout after %.2f seconds. Pool state: %d active, %d available',
                $timeout,
                $poolState['active'] ?? 0,
                $poolState['available'] ?? 0
            ),
            [
                'timeout' => $timeout,
                'pool_state' => $poolState
            ]
        );
    }

    /**
     * Create pool exhausted exception
     *
     * @param int $maxConnections Maximum allowed connections
     * @param int $activeConnections Current active connections
     * @return self
     */
    public static function poolExhausted(int $maxConnections, int $activeConnections): self
    {
        return new self(
            sprintf(
                'Connection pool exhausted: %d/%d connections in use',
                $activeConnections,
                $maxConnections
            ),
            [
                'max_connections' => $maxConnections,
                'active_connections' => $activeConnections
            ]
        );
    }

    /**
     * Create connection creation failure exception
     *
     * @param string $reason Failure reason
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function creationFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            'Failed to create database connection: ' . $reason,
            [
                'reason' => $reason
            ],
            0,
            $previous
        );
    }
}
