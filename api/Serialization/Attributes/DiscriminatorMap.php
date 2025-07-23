<?php

declare(strict_types=1);

namespace Glueful\Serialization\Attributes;

use Symfony\Component\Serializer\Annotation\DiscriminatorMap as SymfonyDiscriminatorMap;

/**
 * DiscriminatorMap Attribute
 *
 * Glueful wrapper for Symfony DiscriminatorMap annotation that enables
 * polymorphic serialization by mapping type identifiers to concrete classes.
 *
 * @package Glueful\Serialization\Attributes
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class DiscriminatorMap extends SymfonyDiscriminatorMap
{
    /**
     * Constructor
     *
     * @param string $typeProperty Property name that contains the type identifier
     * @param array $mapping Array mapping type identifiers to class names
     */
    public function __construct(string $typeProperty, array $mapping)
    {
        parent::__construct($typeProperty, $mapping);
    }
}
