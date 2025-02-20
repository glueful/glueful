<?php
declare(strict_types=1);

namespace Glueful\Api\Exceptions;

/**
 * Not Found Exception
 * 
 * Handles 404 errors for missing resources.
 * Provides standardized error response for resource lookup failures.
 */
class NotFoundException extends ApiException
{
    /**
     * Constructor
     * 
     * Creates a new not found exception with standard 404 code.
     * 
     * @param string $resource Name of resource that wasn't found
     */
    public function __construct(string $resource = 'Resource')
    {
        parent::__construct("$resource not found", 404);
    }
}