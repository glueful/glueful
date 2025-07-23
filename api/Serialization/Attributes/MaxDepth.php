<?php

declare(strict_types=1);

namespace Glueful\Serialization\Attributes;

use Symfony\Component\Serializer\Annotation\MaxDepth as SymfonyMaxDepth;

/**
 * MaxDepth Attribute
 *
 * Glueful wrapper for Symfony MaxDepth annotation that limits
 * the depth of serialization for nested objects to prevent
 * infinite recursion.
 *
 * @package Glueful\Serialization\Attributes
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class MaxDepth extends SymfonyMaxDepth
{
    /**
     * Constructor
     *
     * @param int $maxDepth Maximum depth for serialization
     */
    public function __construct(int $maxDepth)
    {
        parent::__construct($maxDepth);
    }
}
