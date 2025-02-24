<?php

declare(strict_types=1);

namespace Glueful\Validation\Attributes;

use Attribute;

/**
 * Input Sanitization Attribute
 * 
 * Defines sanitization filters for DTO properties.
 * Applied before validation to clean input data.
 * 
 * Example usage:
 * #[Sanitize(['trim', 'strip_tags'])]
 * private string $content;
 * 
 * Available filters:
 * - trim: Remove whitespace from start/end
 * - strip_tags: Remove HTML/PHP tags
 * - intval: Convert to integer
 * - sanitize_email: Clean email address
 * - lowercase: Convert to lowercase
 * - uppercase: Convert to uppercase
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Sanitize
{
    /**
     * Constructor
     * 
     * @param array $filters Array of sanitization filters to apply
     */
    public function __construct(public array $filters) {}
}