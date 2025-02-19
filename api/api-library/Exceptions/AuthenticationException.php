<?php
declare(strict_types=1);

namespace Glueful\Api\Exceptions;

class AuthenticationException extends ApiException
{
    public function __construct(string $message = 'Authentication failed')
    {
        parent::__construct($message, 401);
    }
}