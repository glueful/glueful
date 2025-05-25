<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

/**
 * Rate Limit Exceeded Exception
 *
 * Exception thrown when a rate limit has been exceeded.
 * Returns standard 429 Too Many Requests response.
 */
class RateLimitExceededException extends ApiException
{
    /**
     * Constructor
     *
     * @param string $message Error message
     * @param int $retryAfter Seconds until rate limit resets
     */
    public function __construct(string $message = 'Rate limit exceeded', int $retryAfter = 0)
    {
        parent::__construct($message, 429, ['retry_after' => $retryAfter]);
    }
}
