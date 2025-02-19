<?php
declare(strict_types=1);

namespace Glueful\Api\Exceptions;

class NotFoundException extends ApiException
{
    public function __construct(string $resource = 'Resource')
    {
        parent::__construct("$resource not found", 404);
    }
}