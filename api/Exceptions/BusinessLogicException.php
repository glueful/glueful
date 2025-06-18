<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

/**
 * Business Logic Exception
 *
 * Represents violations of business rules or logic constraints.
 * Used for scenarios where the request is technically valid but
 * violates application business rules.
 *
 * @package Glueful\Exceptions
 */
class BusinessLogicException extends ApiException
{
    /**
     * Create a new business logic exception
     *
     * @param string $message Error message
     * @param array $context Additional context information
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(string $message, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 422, $context, $previous);
    }

    /**
     * Create exception for operation not allowed
     *
     * @param string $operation Operation that was attempted
     * @param string $reason Why it's not allowed
     * @return self
     */
    public static function operationNotAllowed(string $operation, string $reason): self
    {
        return new self(
            "Operation '{$operation}' is not allowed: {$reason}",
            ['operation' => $operation, 'reason' => $reason]
        );
    }

    /**
     * Create exception for state conflicts
     *
     * @param string $resource Resource type
     * @param string $currentState Current state
     * @param string $requiredState Required state
     * @return self
     */
    public static function invalidState(string $resource, string $currentState, string $requiredState): self
    {
        return new self(
            "Cannot perform operation on {$resource}: currently '{$currentState}', requires '{$requiredState}'",
            [
                'resource' => $resource,
                'current_state' => $currentState,
                'required_state' => $requiredState
            ]
        );
    }

    /**
     * Create exception for quota/limit violations
     *
     * @param string $resource Resource type
     * @param int $current Current count
     * @param int $limit Maximum allowed
     * @return self
     */
    public static function limitExceeded(string $resource, int $current, int $limit): self
    {
        return new self(
            "Limit exceeded for {$resource}: {$current}/{$limit}",
            [
                'resource' => $resource,
                'current' => $current,
                'limit' => $limit
            ]
        );
    }

    /**
     * Create exception for dependency violations
     *
     * @param string $resource Resource being operated on
     * @param array $dependencies List of dependent resources
     * @return self
     */
    public static function hasDependencies(string $resource, array $dependencies): self
    {
        return new self(
            "Cannot delete {$resource}: has dependencies on " . implode(', ', $dependencies),
            [
                'resource' => $resource,
                'dependencies' => $dependencies
            ]
        );
    }
}
