<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

/**
 * Database Exception
 *
 * Thrown when database operations fail or validation errors occur.
 * Now extends ApiException for consistent HTTP response handling.
 */
class DatabaseException extends ApiException
{
    /**
     * Create a new database exception
     *
     * @param string $message Exception message
     * @param int $statusCode HTTP status code (defaults to 500)
     * @param array|null $data Additional error data
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $message = "Database operation failed",
        int $statusCode = 500,
        array|null $data = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $data, $previous);
    }

    /**
     * Create exception for connection failure
     *
     * @param string $reason Connection failure reason
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function connectionFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "Database connection failed: $reason",
            500,
            ['connection_error' => true, 'reason' => $reason],
            $previous
        );
    }

    /**
     * Create exception for query failure
     *
     * @param string $operation Database operation (SELECT, INSERT, etc.)
     * @param string $reason Failure reason
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function queryFailed(string $operation, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "Database $operation operation failed: $reason",
            500,
            ['query_error' => true, 'operation' => $operation, 'reason' => $reason],
            $previous
        );
    }

    /**
     * Create exception for constraint violation
     *
     * @param string $constraint Constraint name
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function constraintViolation(string $constraint, ?\Throwable $previous = null): self
    {
        return new self(
            "Database constraint violation: $constraint",
            409,
            ['constraint_violation' => true, 'constraint' => $constraint],
            $previous
        );
    }

    /**
     * Create exception for record creation failures
     *
     * @param string $table Table name
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function createFailed(string $table, ?\Throwable $previous = null): self
    {
        return new self(
            "Failed to create record in {$table}",
            500,
            ['create_error' => true, 'table' => $table],
            $previous
        );
    }

    /**
     * Create exception for record update failures
     *
     * @param string $table Table name
     * @param string $identifier Record identifier
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function updateFailed(string $table, string $identifier, ?\Throwable $previous = null): self
    {
        return new self(
            "Failed to update record in {$table}: {$identifier}",
            500,
            ['update_error' => true, 'table' => $table, 'identifier' => $identifier],
            $previous
        );
    }

    /**
     * Create exception for record deletion failures
     *
     * @param string $table Table name
     * @param string $identifier Record identifier
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function deleteFailed(string $table, string $identifier, ?\Throwable $previous = null): self
    {
        return new self(
            "Failed to delete record from {$table}: {$identifier}",
            500,
            ['delete_error' => true, 'table' => $table, 'identifier' => $identifier],
            $previous
        );
    }
}
