<?php

namespace Glueful\Queue\Exceptions;

/**
 * Driver Not Found Exception
 *
 * Thrown when attempting to access a queue driver that is not registered
 * or available in the system.
 *
 * @package Glueful\Queue\Exceptions
 */
class DriverNotFoundException extends QueueException
{
    /**
     * Create new driver not found exception
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}