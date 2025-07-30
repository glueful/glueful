<?php

declare(strict_types=1);

namespace Glueful\Database\Schema\DTOs;

/**
 * Column Definition Data Transfer Object
 *
 * Represents a single column definition including type, constraints,
 * default values, and metadata. Used to pass column structure
 * between schema builders and SQL generators.
 *
 * Features:
 * - Immutable data structure
 * - Type-safe properties
 * - Constraint validation
 * - Default value handling
 * - Database-agnostic type system
 *
 * Example usage:
 * ```php
 * $column = new ColumnDefinition(
 *     name: 'email',
 *     type: 'string',
 *     length: 255,
 *     nullable: false,
 *     unique: true,
 *     default: null,
 *     comment: 'User email address'
 * );
 * ```
 */
readonly class ColumnDefinition
{
    /**
     * Create a new column definition
     *
     * @param string $name Column name
     * @param string $type Abstract column type (string, integer, boolean, etc.)
     * @param int|null $length Column length for string types
     * @param int|null $precision Precision for decimal types
     * @param int|null $scale Scale for decimal types
     * @param bool $nullable Whether column allows NULL values
     * @param mixed $default Default value (null means no default)
     * @param string|null $defaultRaw Raw SQL expression for default
     * @param bool $autoIncrement Whether column is auto-incrementing
     * @param bool $unsigned Whether numeric column is unsigned
     * @param bool $unique Whether column has unique constraint
     * @param bool $primary Whether column is part of primary key
     * @param string|null $after Column to position after (MySQL)
     * @param bool $first Whether to position as first column (MySQL)
     * @param string|null $comment Column comment
     * @param string|null $charset Character set (MySQL)
     * @param string|null $collation Collation (MySQL)
     * @param bool $binary Whether string column is binary (MySQL)
     * @param bool $zerofill Whether numeric column uses zerofill (MySQL)
     * @param string|null $check Check constraint expression
     * @param array<string, mixed> $options Additional type-specific options
     */
    public function __construct(
        public string $name,
        public string $type,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $nullable = true,
        public mixed $default = null,
        public ?string $defaultRaw = null,
        public bool $autoIncrement = false,
        public bool $unsigned = false,
        public bool $unique = false,
        public bool $primary = false,
        public ?string $after = null,
        public bool $first = false,
        public ?string $comment = null,
        public ?string $charset = null,
        public ?string $collation = null,
        public bool $binary = false,
        public bool $zerofill = false,
        public ?string $check = null,
        public array $options = []
    ) {
        $this->validateType();
        $this->validateConstraints();
        $this->validateOptions();
    }

    /**
     * Check if column has a default value
     *
     * @return bool True if column has default value
     */
    public function hasDefault(): bool
    {
        return $this->default !== null || $this->defaultRaw !== null;
    }

    /**
     * Get effective default value
     *
     * @return mixed Default value or raw expression
     */
    public function getDefaultValue(): mixed
    {
        return $this->defaultRaw ?? $this->default;
    }

    /**
     * Check if column is a numeric type
     *
     * @return bool True if numeric type
     */
    public function isNumeric(): bool
    {
        return in_array($this->type, [
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger',
            'decimal', 'float', 'double', 'real'
        ]);
    }

    /**
     * Check if column is a string type
     *
     * @return bool True if string type
     */
    public function isString(): bool
    {
        return in_array($this->type, [
            'string', 'varchar', 'char', 'text', 'longText', 'mediumText', 'tinyText'
        ]);
    }

    /**
     * Check if column is a date/time type
     *
     * @return bool True if date/time type
     */
    public function isDateTime(): bool
    {
        return in_array($this->type, [
            'timestamp', 'datetime', 'date', 'time', 'year'
        ]);
    }

    /**
     * Check if column supports length specification
     *
     * @return bool True if length is applicable
     */
    public function supportsLength(): bool
    {
        return $this->isString() || in_array($this->type, ['binary', 'varbinary']);
    }

    /**
     * Check if column supports precision/scale
     *
     * @return bool True if precision/scale is applicable
     */
    public function supportsPrecisionScale(): bool
    {
        return in_array($this->type, ['decimal', 'numeric', 'float', 'double']);
    }

    /**
     * Check if column supports unsigned modifier
     *
     * @return bool True if unsigned is applicable
     */
    public function supportsUnsigned(): bool
    {
        return $this->isNumeric();
    }

    /**
     * Check if column supports auto increment
     *
     * @return bool True if auto increment is applicable
     */
    public function supportsAutoIncrement(): bool
    {
        return in_array($this->type, ['integer', 'bigInteger', 'smallInteger', 'tinyInteger']);
    }

    /**
     * Check if column is an enum type
     *
     * @return bool True if enum type
     */
    public function isEnum(): bool
    {
        return $this->type === 'enum';
    }

    /**
     * Get enum values
     *
     * @return array<string> Enum values
     */
    public function getEnumValues(): array
    {
        return $this->options['values'] ?? [];
    }

    /**
     * Check if a value is valid for this enum
     *
     * @param string $value Value to check
     * @return bool True if value is valid
     */
    public function isValidEnumValue(string $value): bool
    {
        return $this->isEnum() && in_array($value, $this->getEnumValues());
    }

    /**
     * Create a copy of this column definition with modifications
     *
     * @param array $changes Changes to apply
     * @return self New column definition instance
     */
    public function with(array $changes): self
    {
        return new self(
            name: $changes['name'] ?? $this->name,
            type: $changes['type'] ?? $this->type,
            length: $changes['length'] ?? $this->length,
            precision: $changes['precision'] ?? $this->precision,
            scale: $changes['scale'] ?? $this->scale,
            nullable: $changes['nullable'] ?? $this->nullable,
            default: $changes['default'] ?? $this->default,
            defaultRaw: $changes['defaultRaw'] ?? $this->defaultRaw,
            autoIncrement: $changes['autoIncrement'] ?? $this->autoIncrement,
            unsigned: $changes['unsigned'] ?? $this->unsigned,
            unique: $changes['unique'] ?? $this->unique,
            primary: $changes['primary'] ?? $this->primary,
            after: $changes['after'] ?? $this->after,
            first: $changes['first'] ?? $this->first,
            comment: $changes['comment'] ?? $this->comment,
            charset: $changes['charset'] ?? $this->charset,
            collation: $changes['collation'] ?? $this->collation,
            binary: $changes['binary'] ?? $this->binary,
            zerofill: $changes['zerofill'] ?? $this->zerofill,
            check: $changes['check'] ?? $this->check,
            options: $changes['options'] ?? $this->options
        );
    }

    /**
     * Validate column type
     *
     * @throws \InvalidArgumentException If type is invalid
     */
    private function validateType(): void
    {
        $validTypes = [
            // Integer types
            'integer', 'bigInteger', 'smallInteger', 'tinyInteger',

            // Decimal types
            'decimal', 'numeric', 'float', 'double', 'real',

            // String types
            'string', 'varchar', 'char', 'text', 'longText', 'mediumText', 'tinyText',

            // Binary types
            'binary', 'varbinary', 'blob', 'longBlob', 'mediumBlob', 'tinyBlob',

            // Date/Time types
            'timestamp', 'datetime', 'date', 'time', 'year',

            // Boolean type
            'boolean',

            // JSON type
            'json', 'jsonb',

            // UUID type
            'uuid',

            // Enum type
            'enum',

            // Special types
            'id', 'foreignId'
        ];

        if (!in_array($this->type, $validTypes)) {
            throw new \InvalidArgumentException("Invalid column type: {$this->type}");
        }
    }

    /**
     * Validate column constraints
     *
     * @throws \InvalidArgumentException If constraints are invalid
     */
    private function validateConstraints(): void
    {
        // Auto increment requires not null
        if ($this->autoIncrement && $this->nullable) {
            throw new \InvalidArgumentException('Auto increment columns cannot be nullable');
        }

        // Auto increment requires numeric type
        if ($this->autoIncrement && !$this->supportsAutoIncrement()) {
            throw new \InvalidArgumentException(
                "Auto increment is not supported for type: {$this->type}"
            );
        }

        // Primary key requires not null
        if ($this->primary && $this->nullable) {
            throw new \InvalidArgumentException('Primary key columns cannot be nullable');
        }

        // Unsigned requires numeric type
        if ($this->unsigned && !$this->supportsUnsigned()) {
            throw new \InvalidArgumentException(
                "Unsigned modifier is not supported for type: {$this->type}"
            );
        }

        // Length validation
        if ($this->length !== null && !$this->supportsLength()) {
            throw new \InvalidArgumentException(
                "Length specification is not supported for type: {$this->type}"
            );
        }

        // Precision/scale validation
        if (($this->precision !== null || $this->scale !== null) && !$this->supportsPrecisionScale()) {
            throw new \InvalidArgumentException(
                "Precision/scale specification is not supported for type: {$this->type}"
            );
        }

        // Positioning validation (MySQL specific)
        if ($this->first && $this->after !== null) {
            throw new \InvalidArgumentException('Column cannot be both FIRST and AFTER another column');
        }
    }

    /**
     * Validate column options
     *
     * @throws \InvalidArgumentException If options are invalid
     */
    private function validateOptions(): void
    {
        // Default value validation
        if ($this->default !== null && $this->defaultRaw !== null) {
            throw new \InvalidArgumentException('Column cannot have both default value and raw default expression');
        }

        // Name validation
        if (empty(trim($this->name))) {
            throw new \InvalidArgumentException('Column name cannot be empty');
        }

        // Length validation
        if ($this->length !== null && $this->length <= 0) {
            throw new \InvalidArgumentException('Column length must be positive');
        }

        // Precision/scale validation
        if ($this->precision !== null && $this->precision <= 0) {
            throw new \InvalidArgumentException('Column precision must be positive');
        }

        if ($this->scale !== null && $this->scale < 0) {
            throw new \InvalidArgumentException('Column scale cannot be negative');
        }

        if ($this->precision !== null && $this->scale !== null && $this->scale > $this->precision) {
            throw new \InvalidArgumentException('Column scale cannot be greater than precision');
        }

        // Enum validation
        if ($this->isEnum()) {
            $enumValues = $this->getEnumValues();
            if (empty($enumValues)) {
                throw new \InvalidArgumentException('Enum column must have at least one value');
            }

            // Validate default value is in enum values
            if ($this->default !== null && !in_array($this->default, $enumValues)) {
                throw new \InvalidArgumentException(
                    'Enum default value must be one of the allowed values: ' . implode(', ', $enumValues)
                );
            }
        }
    }
}
