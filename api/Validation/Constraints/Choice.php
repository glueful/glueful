<?php

declare(strict_types=1);

namespace Glueful\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Choice constraint attribute
 *
 * Validates that a value is one of the given choices.
 * This is a Glueful-specific constraint that maps to Symfony's Choice constraint.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Choice extends Constraint
{
    /** @var string Default error message */
    public string $message = 'The {{ field }} must be one of: {{ choices }}.';

    /** @var string Error message for multiple choices */
    public string $multipleMessage = 'One or more of the given values for {{ field }} is invalid.';

    /** @var array Available choices */
    public array $choices = [];

    /** @var bool Whether multiple choices are allowed */
    public bool $multiple = false;

    /** @var int|null Minimum number of choices (for multiple) */
    public ?int $min = null;

    /** @var int|null Maximum number of choices (for multiple) */
    public ?int $max = null;

    /** @var array<string>|null Validation groups */
    public ?array $groups = null;

    /**
     * Constructor
     *
     * @param array $choices Available choices
     * @param bool $multiple Whether multiple choices are allowed
     * @param int|null $min Minimum number of choices
     * @param int|null $max Maximum number of choices
     * @param string|null $message Custom error message
     * @param string|null $multipleMessage Custom multiple choices message
     * @param array<string> $groups Validation groups
     * @param array $options Additional options
     */
    public function __construct(
        array $choices = [],
        bool $multiple = false,
        ?int $min = null,
        ?int $max = null,
        ?string $message = null,
        ?string $multipleMessage = null,
        array $groups = [],
        array $options = []
    ) {
        $this->choices = $choices;
        $this->multiple = $multiple;
        $this->min = $min;
        $this->max = $max;

        if ($message !== null) {
            $this->message = $message;
        }

        if ($multipleMessage !== null) {
            $this->multipleMessage = $multipleMessage;
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
        return 'Glueful\Validation\ConstraintValidators\ChoiceValidator';
    }
}
