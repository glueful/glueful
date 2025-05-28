<?php

declare(strict_types=1);

namespace Glueful\DI\Exceptions;

use Exception;

/**
 * Container Exception
 *
 * Thrown when there are issues with container operations
 */
class ContainerException extends Exception
{
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Container Error: " . $message, $code, $previous);
    }
}
