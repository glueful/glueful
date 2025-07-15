<?php

declare(strict_types=1);

namespace Glueful\Serialization\Attributes;

use Symfony\Component\Serializer\Annotation\Groups as SymfonyGroups;

/**
 * Groups Attribute
 *
 * Glueful wrapper for Symfony Groups annotation that provides a clean
 * interface for defining serialization groups on properties and methods.
 *
 * @package Glueful\Serialization\Attributes
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class Groups extends SymfonyGroups
{
    /**
     * Constructor
     *
     * @param array|string $groups Single group or array of groups
     */
    public function __construct(array|string $groups)
    {
        parent::__construct($groups);
    }
}
