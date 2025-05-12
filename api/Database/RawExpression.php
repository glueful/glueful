<?php

namespace Glueful\Database;

/**
 * Raw SQL Expression Container
 *
 * Wraps raw SQL expressions to prevent automatic escaping when used in queries.
 * This class helps distinguish between regular strings that should be escaped
 * and raw SQL expressions that should be used as-is.
 *
 * Security note:
 * Only use with trusted input as raw SQL expressions bypass normal escaping
 *
 * @internal Used internally by QueryBuilder
 */
class RawExpression
{
    /** @var string The raw SQL expression */
    protected string $expression;

    /**
     * Create new raw SQL expression
     *
     * @param string $expression Raw SQL to be used without escaping
     */
    public function __construct(string $expression)
    {
        $this->expression = $expression;
    }

    /**
     * Get the raw expression string
     *
     * @return string The raw SQL expression
     */
    public function getExpression(): string
    {
        return $this->expression;
    }

    /**
     * Convert to string when used in string context
     *
     * @return string The raw SQL expression
     */
    public function __toString(): string
    {
        return $this->expression;
    }
}
