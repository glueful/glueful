<?php

declare(strict_types=1);

namespace Glueful\Serialization\Attributes;

use Symfony\Component\Serializer\Annotation\SerializedPath as SymfonySerializedPath;

/**
 * SerializedPath Attribute
 *
 * Glueful wrapper for Symfony SerializedPath annotation that allows
 * mapping nested object properties to flat serialized structures.
 *
 * @package Glueful\Serialization\Attributes
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class SerializedPath extends SymfonySerializedPath
{
    /**
     * Constructor
     *
     * @param string $serializedPath Path in the serialized structure
     */
    public function __construct(string $serializedPath)
    {
        parent::__construct($serializedPath);
    }
}
