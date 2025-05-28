<?php

declare(strict_types=1);

namespace Glueful\DI\Exceptions;

use Exception;

/**
 * Service Not Found Exception
 *
 * Thrown when a requested service cannot be found or resolved
 */
class ServiceNotFoundException extends Exception
{
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Service Not Found: " . $message, $code, $previous);
    }
}