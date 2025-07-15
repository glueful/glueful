<?php

declare(strict_types=1);

namespace Glueful\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * StringLength constraint attribute
 *
 * Validates that a string is between a minimum and maximum length.
 * This is a Glueful-specific constraint that maps to Symfony's Length constraint.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class StringLength extends Constraint
{
    /** @var string Message when string is too short */
    public string $minMessage = 'The {{ field }} must be at least {{ min }} characters.';

    /** @var string Message when string is too long */
    public string $maxMessage = 'The {{ field }} must be at most {{ max }} characters.';

    /** @var string Message when string is not within range */
    public string $exactMessage = 'The {{ field }} must be exactly {{ exact }} characters.';

    /** @var int|null Minimum length */
    public ?int $min = null;

    /** @var int|null Maximum length */
    public ?int $max = null;

    /** @var int|null Exact length */
    public ?int $exact = null;

    /** @var array<string>|null Validation groups */
    public ?array $groups = null;

    /**
     * Constructor
     *
     * @param int|null $min Minimum length
     * @param int|null $max Maximum length
     * @param int|null $exact Exact length required
     * @param string|null $minMessage Custom minimum length message
     * @param string|null $maxMessage Custom maximum length message
     * @param string|null $exactMessage Custom exact length message
     * @param array<string> $groups Validation groups
     * @param array $options Additional options
     */
    public function __construct(
        ?int $min = null,
        ?int $max = null,
        ?int $exact = null,
        ?string $minMessage = null,
        ?string $maxMessage = null,
        ?string $exactMessage = null,
        array $groups = [],
        array $options = []
    ) {
        $this->min = $min;
        $this->max = $max;
        $this->exact = $exact;

        if ($minMessage !== null) {
            $this->minMessage = $minMessage;
        }

        if ($maxMessage !== null) {
            $this->maxMessage = $maxMessage;
        }

        if ($exactMessage !== null) {
            $this->exactMessage = $exactMessage;
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
        return 'Glueful\Validation\ConstraintValidators\StringLengthValidator';
    }
}
