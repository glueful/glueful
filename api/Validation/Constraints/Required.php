<?php

declare(strict_types=1);

namespace Glueful\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Required constraint attribute
 *
 * Validates that a value is not blank (not null and not empty string).
 * This is a Glueful-specific constraint that maps to Symfony's NotBlank constraint.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Required extends Constraint
{
    /** @var string Default error message */
    public string $message = 'The {{ field }} field is required.';

    /** @var array<string>|null Validation groups */
    public ?array $groups = null;

    /**
     * Constructor
     *
     * @param string|null $message Custom error message
     * @param array<string> $groups Validation groups
     * @param array $options Additional options
     */
    public function __construct(
        ?string $message = null,
        array $groups = [],
        array $options = []
    ) {
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
        return 'Glueful\Validation\ConstraintValidators\RequiredValidator';
    }
}
