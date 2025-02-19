<?php

declare(strict_types=1);

namespace Glueful\Api\Library\Validation\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::IS_REPEATABLE)]
class Sanitize
{
    public function __construct(public array $filters) {}
}