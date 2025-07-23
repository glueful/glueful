<?php

declare(strict_types=1);

namespace Glueful\Serialization\Attributes;

use Symfony\Component\Serializer\Annotation\Ignore as SymfonyIgnore;

/**
 * Ignore Attribute
 *
 * Glueful wrapper for Symfony Ignore annotation that marks
 * properties or methods to be excluded from serialization.
 *
 * @package Glueful\Serialization\Attributes
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class Ignore extends SymfonyIgnore
{
    /**
     * Constructor
     */
    public function __construct()
    {
        // Symfony Ignore doesn't have a constructor
    }
}
