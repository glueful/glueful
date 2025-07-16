<?php

declare(strict_types=1);

namespace Glueful\Http\Exceptions;

use Glueful\Exceptions\HttpException;

/**
 * HTTP Client Exception
 *
 * Exception thrown by the HTTP client when a request fails.
 * Extends the framework's HttpException for compatibility with existing event system.
 */
class HttpClientException extends HttpException
{
    public function __construct(string $message = '', int $code = 0)
    {
        parent::__construct($message, $code);
    }
}
