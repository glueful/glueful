<?php

declare(strict_types=1);

namespace Glueful\Validation;

/**
 * Conditional Builder
 *
 * Handles conditional validation logic.
 */
class ConditionalBuilder
{
    /** @var string Field to check */
    private string $field;

    /** @var mixed Value to compare against */
    private mixed $value;

    /**
     * Constructor
     *
     * @param string $field Field to check
     * @param mixed $value Value to compare against
     */
    public function __construct(string $field, mixed $value)
    {
        $this->field = $field;
        $this->value = $value;
    }

    /**
     * Apply constraints when condition is met
     *
     * @param array $constraints Constraint rules to apply
     * @return array Conditional constraint configuration
     */
    public function then(array $constraints): array
    {
        return [
            'type' => 'conditional',
            'field' => $this->field,
            'value' => $this->value,
            'constraints' => $constraints,
        ];
    }
}
