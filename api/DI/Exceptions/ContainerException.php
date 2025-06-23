<?php

declare(strict_types=1);

namespace Glueful\DI\Exceptions;

use Exception;
use Psr\Container\ContainerExceptionInterface;

/**
 * Container Exception
 *
 * Thrown when there are issues with container operations.
 * Implements PSR-11 ContainerExceptionInterface for standards compliance.
 */
class ContainerException extends Exception implements ContainerExceptionInterface
{
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct("Container Error: " . $message, $code, $previous);
    }
}
