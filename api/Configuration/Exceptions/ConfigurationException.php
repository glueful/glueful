<?php

declare(strict_types=1);

namespace Glueful\Configuration\Exceptions;

use Exception;

/**
 * Configuration Exception
 *
 * Thrown when configuration processing, validation, or schema-related operations fail.
 * Provides specific context for configuration-related errors.
 *
 * @package Glueful\Configuration\Exceptions
 */
class ConfigurationException extends Exception
{
    /**
     * Create a new configuration exception
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create exception for missing schema
     */
    public static function schemaNotFound(string $configName): self
    {
        return new self("No schema registered for configuration: {$configName}");
    }

    /**
     * Create exception for invalid configuration data
     */
    public static function invalidConfiguration(string $configName, string $reason): self
    {
        return new self("Invalid configuration for {$configName}: {$reason}");
    }

    /**
     * Create exception for schema registration failures
     */
    public static function schemaRegistrationFailed(string $configName, string $reason): self
    {
        return new self("Failed to register schema for {$configName}: {$reason}");
    }

    /**
     * Create exception for configuration processing failures
     */
    public static function processingFailed(string $configName, string $reason): self
    {
        return new self("Failed to process configuration for {$configName}: {$reason}");
    }
}
