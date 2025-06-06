<?php

declare(strict_types=1);

namespace Glueful\Permissions\Exceptions;

/**
 * Unauthorized Exception
 *
 * Thrown when a user attempts to access a resource or perform
 * an action they don't have permission for.
 *
 * @package Glueful\Permissions\Exceptions
 */
class UnauthorizedException extends PermissionException
{
    /** @var string User UUID who attempted the action */
    private string $userUuid;

    /** @var string Permission that was required */
    private string $requiredPermission;

    /** @var string Resource that was accessed */
    private string $resource;

    /**
     * Create a new unauthorized exception
     *
     * @param string $userUuid User UUID
     * @param string $requiredPermission Required permission
     * @param string $resource Resource being accessed
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(
        string $userUuid,
        string $requiredPermission,
        string $resource,
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $this->userUuid = $userUuid;
        $this->requiredPermission = $requiredPermission;
        $this->resource = $resource;

        if (empty($message)) {
            $message = "User '{$userUuid}' does not have permission '{$requiredPermission}' for resource '{$resource}'";
        }

        parent::__construct(
            $message,
            $code ?: 3001,
            $previous,
            [
                'user' => $userUuid,
                'permission' => $requiredPermission,
                'resource' => $resource
            ]
        );
    }

    /**
     * Get the user UUID who attempted the action
     *
     * @return string User UUID
     */
    public function getUserUuid(): string
    {
        return $this->userUuid;
    }

    /**
     * Get the required permission
     *
     * @return string Required permission
     */
    public function getRequiredPermission(): string
    {
        return $this->requiredPermission;
    }

    /**
     * Get the resource that was accessed
     *
     * @return string Resource
     */
    public function getResource(): string
    {
        return $this->resource;
    }

    /**
     * Create exception for insufficient permissions
     *
     * @param string $userUuid User UUID
     * @param string $permission Required permission
     * @param string $resource Resource
     * @return self
     */
    public static function insufficientPermissions(string $userUuid, string $permission, string $resource): self
    {
        return new self(
            $userUuid,
            $permission,
            $resource,
            "Insufficient permissions to access '{$resource}' with permission '{$permission}'",
            3001
        );
    }

    /**
     * Create exception for expired permissions
     *
     * @param string $userUuid User UUID
     * @param string $permission Required permission
     * @param string $resource Resource
     * @return self
     */
    public static function permissionExpired(string $userUuid, string $permission, string $resource): self
    {
        return new self(
            $userUuid,
            $permission,
            $resource,
            "Permission '{$permission}' for resource '{$resource}' has expired",
            3002
        );
    }

    /**
     * Create exception for disabled user
     *
     * @param string $userUuid User UUID
     * @return self
     */
    public static function userDisabled(string $userUuid): self
    {
        return new self(
            $userUuid,
            'any',
            'any',
            "User '{$userUuid}' is disabled and cannot access any resources",
            3003
        );
    }
}
