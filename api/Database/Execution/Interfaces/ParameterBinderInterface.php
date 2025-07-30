<?php

declare(strict_types=1);

namespace Glueful\Database\Execution\Interfaces;

/**
 * ParameterBinder Interface
 *
 * Defines the contract for parameter binding functionality.
 * This interface ensures consistent parameter binding across
 * different implementations.
 */
interface ParameterBinderInterface
{
    /**
     * Flatten bindings to prevent nested arrays
     */
    public function flattenBindings(array $bindings): array;

    /**
     * Bind parameters to a prepared statement
     */
    public function bindParameters(\PDOStatement $statement, array $bindings): void;

    /**
     * Sanitize parameter for logging (remove sensitive data)
     */
    public function sanitizeForLog($parameter): mixed;

    /**
     * Sanitize array of parameters for logging
     */
    public function sanitizeBindingsForLog(array $bindings): array;

    /**
     * Validate parameter type
     */
    public function validateParameter($parameter): bool;
}
