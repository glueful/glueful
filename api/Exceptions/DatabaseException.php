<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

/**
 * Database Exception
 *
 * Thrown when database operations fail or validation errors occur.
 */
class DatabaseException extends \Exception
{
    /**
     * Create a new database exception
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = "Database operation failed",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
