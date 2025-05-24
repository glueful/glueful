<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

/**
 * Validation Exception
 *
 * Handles validation failures in the API.
 * Stores and provides access to validation error messages.
 */
class ValidationException extends ApiException
{
    /** @var array Validation error messages */
    private array $errors;

    /**
     * Constructor
     *
     * @param string|array $errors Error message or array of validation error messages
     * @param int $statusCode HTTP status code (defaults to 422)
     * @param array|null $details Additional error details
     */
    public function __construct($errors, int $statusCode = 422, array|null $details = null)
    {
        if (is_string($errors)) {
            $message = $errors;
            $this->errors = $details ?? [];
        } else {
            $message = 'Validation failed';
            $this->errors = $errors;
        }

        parent::__construct($message, $statusCode, $this->errors);
    }

    /**
     * Get validation errors
     *
     * @return array Array of validation error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
