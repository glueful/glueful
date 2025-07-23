<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions;

use Exception;

/**
 * HTTP Response Exception
 *
 * Exception thrown when response processing fails.
 */
class HttpResponseException extends Exception
{
    public function __construct(string $message = '', int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
