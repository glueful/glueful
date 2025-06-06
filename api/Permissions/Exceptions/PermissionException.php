<?php

declare(strict_types=1);

namespace Glueful\Permissions\Exceptions;

/**
 * Permission Exception
 *
 * General exception for permission-related errors.
 * Used for configuration errors, provider failures,
 * and other permission system issues.
 *
 * @package Glueful\Permissions\Exceptions
 */
class PermissionException extends \Exception
{
    /** @var array Additional context data */
    private array $context;

    /**
     * Create a new permission exception
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     * @param array $context Additional context data
     */
    public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get additional context data
     *
     * @return array Context data
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set additional context data
     *
     * @param array $context Context data
     * @return void
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /**
     * Create exception for provider initialization failure
     *
     * @param string $providerName Provider name
     * @param string $reason Failure reason
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function providerInitializationFailed(
        string $providerName,
        string $reason,
        ?\Throwable $previous = null
    ): self {
        return new self(
            "Failed to initialize permission provider '{$providerName}': {$reason}",
            1001,
            $previous,
            ['provider' => $providerName, 'reason' => $reason]
        );
    }

    /**
     * Create exception for permission check failure
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @param string $reason Failure reason
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function permissionCheckFailed(
        string $userUuid,
        string $permission,
        string $resource,
        string $reason,
        ?\Throwable $previous = null
    ): self {
        return new self(
            "Permission check failed for user '{$userUuid}', permission '{$permission}', " .
            "resource '{$resource}': {$reason}",
            1002,
            $previous,
            [
                'user' => $userUuid,
                'permission' => $permission,
                'resource' => $resource,
                'reason' => $reason
            ]
        );
    }

    /**
     * Create exception for invalid configuration
     *
     * @param string $configKey Configuration key
     * @param string $reason Reason for invalidity
     * @return self
     */
    public static function invalidConfiguration(string $configKey, string $reason): self
    {
        return new self(
            "Invalid permission configuration for '{$configKey}': {$reason}",
            1003,
            null,
            ['config_key' => $configKey, 'reason' => $reason]
        );
    }

    /**
     * Create exception for operation failure
     *
     * @param string $operation Operation name
     * @param string $reason Failure reason
     * @param \Throwable|null $previous Previous exception
     * @return self
     */
    public static function operationFailed(string $operation, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            "Permission operation '{$operation}' failed: {$reason}",
            1004,
            $previous,
            ['operation' => $operation, 'reason' => $reason]
        );
    }
}
