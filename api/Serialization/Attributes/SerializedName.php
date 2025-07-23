<?php

declare(strict_types=1);

namespace Glueful\Serialization\Attributes;

use Symfony\Component\Serializer\Annotation\SerializedName as SymfonySerializedName;

/**
 * SerializedName Attribute
 *
 * Glueful wrapper for Symfony SerializedName annotation that allows
 * properties to be serialized with a different name than their
 * property name.
 *
 * @package Glueful\Serialization\Attributes
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class SerializedName extends SymfonySerializedName
{
    /**
     * Constructor
     *
     * @param string $serializedName The name to use during serialization
     */
    public function __construct(string $serializedName)
    {
        parent::__construct($serializedName);
    }
}
