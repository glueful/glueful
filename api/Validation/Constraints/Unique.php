<?php

declare(strict_types=1);

namespace Glueful\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Unique constraint attribute
 *
 * Validates that a value is unique in the database table.
 * Useful for ensuring unique emails, usernames, SKUs, etc.
 *
 * Example usage:
 * ```php
 * class UserDTO {
 *     #[Unique(table: 'users', column: 'email', message: 'Email already exists')]
 *     public string $email;
 *
 *     #[Unique(table: 'users', column: 'username', ignoreId: 'id')]
 *     public string $username;
 * }
 * ```
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Unique extends Constraint
{
    /** @var string Default error message */
    public string $message = 'The {{ field }} "{{ value }}" already exists.';

    /** @var string Database table name */
    public string $table = '';

    /** @var string Column name to check (defaults to property name) */
    public string $column = '';

    /** @var string|null ID field name to ignore during updates */
    public ?string $ignoreId = null;

    /** @var mixed Value of ID to ignore during updates */
    public mixed $ignoreValue = null;

    /** @var array Additional where conditions */
    public array $conditions = [];

    /** @var array<string>|null Validation groups */
    public ?array $groups = null;

    /**
     * Constructor
     *
     * @param string $table Database table name
     * @param string $column Column name to check
     * @param string|null $ignoreId ID field name to ignore
     * @param mixed $ignoreValue Value of ID to ignore
     * @param array $conditions Additional where conditions
     * @param string|null $message Custom error message
     * @param array<string> $groups Validation groups
     * @param array $options Additional options
     */
    public function __construct(
        string $table,
        string $column = '',
        ?string $ignoreId = null,
        mixed $ignoreValue = null,
        array $conditions = [],
        ?string $message = null,
        array $groups = [],
        array $options = []
    ) {
        $this->table = $table;
        $this->column = $column;
        $this->ignoreId = $ignoreId;
        $this->ignoreValue = $ignoreValue;
        $this->conditions = $conditions;

        if ($message !== null) {
            $this->message = $message;
        }

        $this->groups = !empty($groups) ? $groups : null;

        parent::__construct($options);
    }

    /**
     * Get the validator class name
     *
     * @return string Validator class name
     */
    public function validatedBy(): string
    {
        return 'Glueful\Validation\ConstraintValidators\UniqueValidator';
    }
}
