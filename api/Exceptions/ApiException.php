<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

use Exception;

/**
 * Base API Exception
 *
 * Base exception class for API errors.
 * Provides status code and additional data handling.
 */
class ApiException extends Exception
{
    /** @var array|null Additional error context data */
    private array|null $data;

    /**
     * Constructor
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param array|null $data Additional error data
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message,
        int $statusCode = 400,
        array|null $data = null,
        \Throwable|null $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
        $this->data = $data;
    }

    /**
     * Get HTTP status code
     *
     * @return int HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->getCode();
    }

    /**
     * Get additional error data
     *
     * @return array|null Additional context data
     */
    public function getData(): array|null
    {
        return $this->data;
    }
}
