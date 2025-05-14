<?php

declare(strict_types=1);

namespace Glueful\Exceptions;

/**
 * Extension Exception
 *
 * Exception class for extension-related errors.
 * Used for issues with extension loading, compatibility, installation,
 * configuration, and other extension system operations.
 */
class ExtensionException extends ApiException
{
    /**
     * Constructor
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param array|null $data Additional error data
     */
    public function __construct(string $message, int $statusCode = 400, array|null $data = null)
    {
        parent::__construct($message, $statusCode, $data);
    }
}
