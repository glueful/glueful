<?php

declare(strict_types=1);

namespace Glueful\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Email constraint attribute
 *
 * Validates that a value is a valid email address.
 * This is a Glueful-specific constraint that maps to Symfony's Email constraint.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Email extends Constraint
{
    /** @var string Default error message */
    public string $message = 'The {{ field }} must be a valid email address.';

    /** @var string Validation mode */
    public string $mode = 'html5';

    /** @var array<string>|null Validation groups */
    public ?array $groups = null;

    /**
     * Constructor
     *
     * @param string|null $message Custom error message
     * @param string $mode Validation mode (html5, html5-allow-no-tld, strict)
     * @param array<string> $groups Validation groups
     * @param array $options Additional options
     */
    public function __construct(
        ?string $message = null,
        string $mode = 'html5',
        array $groups = [],
        array $options = []
    ) {
        if ($message !== null) {
            $this->message = $message;
        }

        $this->mode = $mode;
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
        return 'Glueful\Validation\ConstraintValidators\EmailValidator';
    }
}
