<?php

declare(strict_types=1);

namespace Glueful\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * ConditionalRequired constraint attribute
 *
 * Validates that a field is required only when another field has a specific value.
 * Useful for conditional form fields and dynamic business rules.
 *
 * Example usage:
 * ```php
 * class OrderDTO {
 *     #[ConditionalRequired(
 *         when: 'paymentMethod',
 *         equals: 'credit_card',
 *         message: 'Credit card number is required for credit card payments'
 *     )]
 *     public ?string $creditCardNumber;
 * }
 * ```
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class ConditionalRequired extends Constraint
{
    /** @var string Default error message */
    public string $message = 'The {{ field }} field is required when {{ when }} is {{ equals }}.';

    /** @var string Field name to check condition against */
    public string $when = '';

    /** @var string Target field that becomes required when condition is met */
    public string $field = '';

    /** @var mixed Value that triggers the requirement */
    public mixed $equals = null;

    /** @var string Comparison operator (equals, not_equals, in, not_in, empty, not_empty) */
    public string $operator = 'equals';

    /** @var array<string>|null Validation groups */
    public ?array $groups = null;

    /**
     * Constructor
     *
     * @param string $when Field name to check condition against
     * @param string $field Target field that becomes required
     * @param mixed $equals Value that triggers the requirement
     * @param string $operator Comparison operator
     * @param string|null $message Custom error message
     * @param array<string> $groups Validation groups
     * @param array $options Additional options
     */
    public function __construct(
        string $when,
        string $field,
        mixed $equals = null,
        string $operator = 'equals',
        ?string $message = null,
        array $groups = [],
        array $options = []
    ) {
        $this->when = $when;
        $this->field = $field;
        $this->equals = $equals;
        $this->operator = $operator;

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
        return 'Glueful\Validation\ConstraintValidators\ConditionalRequiredValidator';
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
