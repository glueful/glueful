<?php

declare(strict_types=1);

namespace Glueful\Database\Features\Interfaces;

use Glueful\Database\Query\Interfaces\QueryStateInterface;

/**
 * Interface for query validation functionality
 *
 * Provides methods to validate query components before execution
 * to ensure data integrity and prevent common errors.
 */
interface QueryValidatorInterface
{
    /**
     * Validate a complete query state
     *
     * @param QueryStateInterface $state The query state to validate
     * @throws \InvalidArgumentException If validation fails
     */
    public function validate(QueryStateInterface $state): void;

    /**
     * Validate SELECT query components
     *
     * @param QueryStateInterface $state The query state to validate
     * @throws \InvalidArgumentException If validation fails
     */
    public function validateSelect(QueryStateInterface $state): void;

    /**
     * Validate INSERT data
     *
     * @param string $table The table name
     * @param array $data The data to insert
     * @throws \InvalidArgumentException If validation fails
     */
    public function validateInsert(string $table, array $data): void;

    /**
     * Validate UPDATE data
     *
     * @param string $table The table name
     * @param array $data The data to update
     * @param array $conditions The WHERE conditions
     * @throws \InvalidArgumentException If validation fails
     */
    public function validateUpdate(string $table, array $data, array $conditions): void;

    /**
     * Validate DELETE conditions
     *
     * @param string $table The table name
     * @param array $conditions The WHERE conditions
     * @throws \InvalidArgumentException If validation fails
     */
    public function validateDelete(string $table, array $conditions): void;

    /**
     * Validate table name
     *
     * @param string $table The table name to validate
     * @throws \InvalidArgumentException If validation fails
     */
    public function validateTableName(string $table): void;

    /**
     * Validate column names
     *
     * @param array $columns The column names to validate
     * @throws \InvalidArgumentException If validation fails
     */
    public function validateColumnNames(array $columns): void;

    /**
     * Validate limit and offset values
     *
     * @param ?int $limit The limit value
     * @param ?int $offset The offset value
     * @throws \InvalidArgumentException If validation fails
     */
    public function validatePagination(?int $limit, ?int $offset): void;

    /**
     * Enable or disable strict validation mode
     */
    public function setStrictMode(bool $strict): void;

    /**
     * Check if strict validation mode is enabled
     */
    public function isStrictMode(): bool;

    /**
     * Add custom validation rule
     *
     * @param string $name The rule name
     * @param callable $validator The validation callback
     */
    public function addRule(string $name, callable $validator): void;

    /**
     * Remove a custom validation rule
     */
    public function removeRule(string $name): void;
}
