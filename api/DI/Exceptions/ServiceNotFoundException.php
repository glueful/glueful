<?php

declare(strict_types=1);

namespace Glueful\DI\Exceptions;

use Exception;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Service Not Found Exception
 *
 * Thrown when a requested service cannot be found or resolved.
 * Implements PSR-11 NotFoundExceptionInterface for standards compliance.
 */
class ServiceNotFoundException extends Exception implements NotFoundExceptionInterface
{
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Service Not Found: " . $message, $code, $previous);
    }
}
