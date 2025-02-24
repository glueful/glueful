<?php

declare(strict_types=1);

namespace Glueful\Validation\Attributes;

use Attribute;

/**
 * Validation Rules Attribute
 * 
 * Defines validation rules for DTO properties.
 * Can be applied multiple times to the same property.
 * 
 * Example usage:
 * #[Rules(['required', 'string', 'min:3'])]
 * private string $name;
 * 
 * Available rules:
 * - required: Field must not be empty
 * - string: Must be string type
 * - int: Must be integer type
 * - min:n: Minimum length/value
 * - max:n: Maximum length/value
 * - between:min,max: Value must be in range
 * - email: Must be valid email format
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Rules
{
    /**
     * Constructor
     * 
     * @param array $rules Array of validation rules
     */
    public function __construct(public array $rules) {}
}