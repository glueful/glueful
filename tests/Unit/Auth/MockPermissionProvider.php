<?php

namespace Tests\Unit\Auth;

use Glueful\Interfaces\Permission\PermissionProviderInterface;

/**
 * Mock permission provider for testing
 */
class MockPermissionProvider implements PermissionProviderInterface
{
    private array $userPermissions = [];

    public function setUserPermission(string $userUuid, string $permission, string $resource): self
    {
        if (!isset($this->userPermissions[$userUuid])) {
            $this->userPermissions[$userUuid] = [];
        }
        if (!isset($this->userPermissions[$userUuid][$resource])) {
            $this->userPermissions[$userUuid][$resource] = [];
        }
        $this->userPermissions[$userUuid][$resource][] = $permission;
        return $this;
    }

    public function initialize(array $config = []): void
    {
        // Mock implementation - no initialization needed
    }

    public function can(string $userUuid, string $permission, string $resource, array $context = []): bool
    {
        return isset($this->userPermissions[$userUuid][$resource]) &&
               in_array($permission, $this->userPermissions[$userUuid][$resource]);
    }

    public function getUserPermissions(string $userUuid): array
    {
        return $this->userPermissions[$userUuid] ?? [];
    }

    public function assignPermission(string $userUuid, string $permission, string $resource, array $options = []): bool
    {
        $this->setUserPermission($userUuid, $permission, $resource);
        return true;
    }

    public function revokePermission(string $userUuid, string $permission, string $resource): bool
    {
        if (isset($this->userPermissions[$userUuid][$resource])) {
            $key = array_search($permission, $this->userPermissions[$userUuid][$resource]);
            if ($key !== false) {
                unset($this->userPermissions[$userUuid][$resource][$key]);
            }
        }
        return true;
    }

    public function getAvailablePermissions(): array
    {
        return [
            'system.access' => 'System Access',
            'users.view' => 'View Users',
            'users.create' => 'Create Users',
            'users.edit' => 'Edit Users',
            'users.delete' => 'Delete Users'
        ];
    }

    public function getAvailableResources(): array
    {
        return ['system' => 'System Resources'];
    }

    public function batchAssignPermissions(string $userUuid, array $permissions, array $options = []): bool
    {
        foreach ($permissions as $perm) {
            $this->assignPermission($userUuid, $perm['permission'], $perm['resource'], $options);
        }
        return true;
    }

    public function batchRevokePermissions(string $userUuid, array $permissions): bool
    {
        foreach ($permissions as $perm) {
            $this->revokePermission($userUuid, $perm['permission'], $perm['resource']);
        }
        return true;
    }

    public function invalidateUserCache(string $userUuid): void
    {
        // Mock implementation - no cache to invalidate
    }

    public function invalidateAllCache(): void
    {
        // Mock implementation - no cache to invalidate
    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'Mock Permission Provider',
            'version' => '1.0.0',
            'description' => 'Mock provider for testing'
        ];
    }

    public function healthCheck(): array
    {
        return ['status' => 'ok'];
    }

    public function assignRole(string $userUuid, string $roleSlug, array $options = []): bool
    {
        // Mock implementation - for testing purposes
        // In a real implementation, this would assign the role to the user
        return true;
    }

    public function revokeRole(string $userUuid, string $roleSlug): bool
    {
        // Mock implementation - for testing purposes
        // In a real implementation, this would revoke the role from the user
        return true;
    }
}
