<?php

declare(strict_types=1);

namespace Glueful\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Range constraint attribute
 *
 * Validates that a numeric value is within a specified range.
 * This is a Glueful-specific constraint that maps to Symfony's Range constraint.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class Range extends Constraint
{
    /** @var string Message when value is below minimum */
    public string $minMessage = 'The {{ field }} must be at least {{ min }}.';

    /** @var string Message when value is above maximum */
    public string $maxMessage = 'The {{ field }} must be at most {{ max }}.';

    /** @var string Message when value is not within range */
    public string $notInRangeMessage = 'The {{ field }} must be between {{ min }} and {{ max }}.';

    /** @var int|float|null Minimum value */
    public int|float|null $min = null;

    /** @var int|float|null Maximum value */
    public int|float|null $max = null;

    /** @var array<string>|null Validation groups */
    public ?array $groups = null;

    /**
     * Constructor
     *
     * @param int|float|null $min Minimum value
     * @param int|float|null $max Maximum value
     * @param string|null $minMessage Custom minimum message
     * @param string|null $maxMessage Custom maximum message
     * @param string|null $notInRangeMessage Custom range message
     * @param array<string> $groups Validation groups
     * @param array $options Additional options
     */
    public function __construct(
        int|float|null $min = null,
        int|float|null $max = null,
        ?string $minMessage = null,
        ?string $maxMessage = null,
        ?string $notInRangeMessage = null,
        array $groups = [],
        array $options = []
    ) {
        $this->min = $min;
        $this->max = $max;

        if ($minMessage !== null) {
            $this->minMessage = $minMessage;
        }

        if ($maxMessage !== null) {
            $this->maxMessage = $maxMessage;
        }

        if ($notInRangeMessage !== null) {
            $this->notInRangeMessage = $notInRangeMessage;
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
        return 'Glueful\Validation\ConstraintValidators\RangeValidator';
    }
}
