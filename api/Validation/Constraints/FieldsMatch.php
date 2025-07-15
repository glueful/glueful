<?php

declare(strict_types=1);

namespace Glueful\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * FieldsMatch constraint attribute
 *
 * Validates that two fields have matching values.
 * Commonly used for password confirmation, email verification, etc.
 *
 * Example usage:
 * ```php
 * class PasswordChangeDTO {
 *     public string $newPassword;
 *
 *     #[FieldsMatch(field: 'newPassword', message: 'Passwords must match')]
 *     public string $confirmPassword;
 * }
 * ```
 *
 * @Annotation
 * @Target({"CLASS", "ANNOTATION"})
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class FieldsMatch extends Constraint
{
    /** @var string Default error message */
    public string $message = 'The {{ field }} must match {{ otherField }}.';

    /** @var string First field name */
    public string $field = '';

    /** @var string Second field name */
    public string $otherField = '';

    /** @var bool Whether comparison should be case sensitive */
    public bool $caseSensitive = true;

    /** @var array<string>|null Validation groups */
    public ?array $groups = null;

    /**
     * Constructor
     *
     * @param string $field First field name
     * @param string $otherField Second field name (if empty, uses field + 'Confirmation')
     * @param bool $caseSensitive Whether comparison should be case sensitive
     * @param string|null $message Custom error message
     * @param array<string> $groups Validation groups
     * @param array $options Additional options
     */
    public function __construct(
        string $field,
        string $otherField = '',
        bool $caseSensitive = true,
        ?string $message = null,
        array $groups = [],
        array $options = []
    ) {
        $this->field = $field;
        $this->otherField = $otherField ?: $field . 'Confirmation';
        $this->caseSensitive = $caseSensitive;

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
        return 'Glueful\Validation\ConstraintValidators\FieldsMatchValidator';
    }

    /**
     * Get the targets for this constraint
     *
     * @return string|array The validation target(s)
     */
    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
