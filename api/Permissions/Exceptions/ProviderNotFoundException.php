<?php

declare(strict_types=1);

namespace Glueful\Permissions\Exceptions;

/**
 * Provider Not Found Exception
 *
 * Thrown when a requested permission provider is not registered
 * or cannot be found in the system.
 *
 * @package Glueful\Permissions\Exceptions
 */
class ProviderNotFoundException extends PermissionException
{
    /** @var string The name of the provider that was not found */
    private string $providerName;

    /**
     * Create a new provider not found exception
     *
     * @param string $providerName Name of the provider that was not found
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $providerName, string $message = "", int $code = 0, ?\Throwable $previous = null)
    {
        $this->providerName = $providerName;

        if (empty($message)) {
            $message = "Permission provider '{$providerName}' not found";
        }

        parent::__construct($message, $code ?: 2001, $previous, ['provider' => $providerName]);
    }

    /**
     * Get the name of the provider that was not found
     *
     * @return string Provider name
     */
    public function getProviderName(): string
    {
        return $this->providerName;
    }

    /**
     * Create exception for provider not registered
     *
     * @param string $providerName Provider name
     * @return self
     */
    public static function notRegistered(string $providerName): self
    {
        return new self(
            $providerName,
            "Permission provider '{$providerName}' is not registered",
            2001
        );
    }

    /**
     * Create exception for no active provider
     *
     * @return self
     */
    public static function noActiveProvider(): self
    {
        return new self(
            'none',
            "No permission provider is currently active",
            2002
        );
    }

    /**
     * Create exception for provider discovery failure
     *
     * @param string $criteria Search criteria
     * @return self
     */
    public static function discoveryFailed(string $criteria): self
    {
        return new self(
            'discovery',
            "No permission providers found matching criteria: {$criteria}",
            2003
        );
    }
}
