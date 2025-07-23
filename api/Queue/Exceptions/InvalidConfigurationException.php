<?php

namespace Glueful\Queue\Exceptions;

/**
 * Invalid Configuration Exception
 *
 * Thrown when queue driver configuration is invalid, missing required
 * parameters, or fails validation against the driver's schema.
 *
 * @package Glueful\Queue\Exceptions
 */
class InvalidConfigurationException extends QueueException
{
    /**
     * Create new invalid configuration exception
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
