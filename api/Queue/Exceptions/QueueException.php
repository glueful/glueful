<?php

namespace Glueful\Queue\Exceptions;

/**
 * Base Queue Exception
 *
 * Base exception class for all queue-related exceptions.
 * Provides common functionality and error handling patterns.
 *
 * @package Glueful\Queue\Exceptions
 */
class QueueException extends \Exception
{
    /**
     * Create new queue exception
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get context information for logging
     *
     * @return array Context data
     */
    public function getContext(): array
    {
        return [
            'exception' => static::class,
            'message' => $this->getMessage(),
            'code' => $this->getCode(),
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'trace' => $this->getTraceAsString()
        ];
    }
}
