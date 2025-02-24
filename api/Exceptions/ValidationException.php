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
     * @param array $errors Array of validation error messages
     */
    public function __construct(array $errors)
    {
        parent::__construct('Validation failed', 422, $errors);
        $this->errors = $errors;
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