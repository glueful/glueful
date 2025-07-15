<?php

declare(strict_types=1);

namespace Glueful\Serialization\Attributes;

use Symfony\Component\Serializer\Annotation\Context as SymfonyContext;

/**
 * Context Attribute
 *
 * Glueful wrapper for Symfony Context annotation that allows
 * setting context parameters for specific properties during
 * serialization and deserialization.
 *
 * @package Glueful\Serialization\Attributes
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class Context extends SymfonyContext
{
    /**
     * Constructor
     *
     * @param array $context Context parameters
     * @param array $groups Serialization groups to apply context to
     */
    public function __construct(array $context = [], array $groups = [])
    {
        parent::__construct($context, $groups);
    }
}
