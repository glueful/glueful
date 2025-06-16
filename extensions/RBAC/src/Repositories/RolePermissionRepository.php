<?php

declare(strict_types=1);

namespace Glueful\Extensions\RBAC\Repositories;

use Glueful\Repository\BaseRepository;
use Glueful\Extensions\RBAC\Models\RolePermission;
use Glueful\Helpers\Utils;

/**
 * Role Permission Repository
 *
 * Manages role-permission assignments in the RBAC system.
 * Handles the many-to-many relationship between roles and permissions.
 */
class RolePermissionRepository extends BaseRepository
{
    protected string $resourceName = 'role_permissions';
    protected string $uuidField = 'uuid';
    protected ?string $modelClass = RolePermission::class;

    /**
     * Get table name
     *
     * @return string
     */
    public function getTableName(): string
    {
        return $this->resourceName;
    }

    /**
     * Assign permission to role
     *
     * @param string $roleUuid Role UUID
     * @param string $permissionUuid Permission UUID
     * @param array $options Assignment options
     * @return RolePermission|null Created assignment
     */
    public function assignPermissionToRole(
        string $roleUuid,
        string $permissionUuid,
        array $options = []
    ): ?RolePermission {
        // Check if already assigned
        $existing = $this->findWhere([
            'role_uuid' => $roleUuid,
            'permission_uuid' => $permissionUuid
        ]);

        if (!empty($existing)) {
            return new RolePermission($existing[0]); // Already assigned
        }

        $data = [
            'uuid' => Utils::generateNanoID(),
            'role_uuid' => $roleUuid,
            'permission_uuid' => $permissionUuid,
            'granted_by' => $options['granted_by'] ?? null,
            'expires_at' => $options['expires_at'] ?? null
        ];

        // Set resource filter if provided
        if (isset($options['resource_filter'])) {
            $data['resource_filter'] = json_encode($options['resource_filter']);
        }

        // Set constraints if provided
        if (isset($options['constraints'])) {
            $data['constraints'] = json_encode($options['constraints']);
        }

        $createdUuid = $this->create($data);
        if (!$createdUuid) {
            return null;
        }

        // Retrieve the full record
        $createdRecord = $this->find($createdUuid);
        return $createdRecord ? new RolePermission($createdRecord) : null;
    }

    /**
     * Revoke permission from role
     *
     * @param string $roleUuid Role UUID
     * @param string $permissionUuid Permission UUID
     * @return bool Success status
     */
    public function revokePermissionFromRole(string $roleUuid, string $permissionUuid): bool
    {
        $assignments = $this->findWhere([
            'role_uuid' => $roleUuid,
            'permission_uuid' => $permissionUuid
        ]);

        if (empty($assignments)) {
            return true; // Already revoked
        }

        foreach ($assignments as $assignment) {
            $this->delete($assignment['uuid']);
        }

        return true;
    }

    /**
     * Get all permissions for a role
     *
     * @param string $roleUuid Role UUID
     * @param array $filters Optional filters
     * @return array Role permissions
     */
    public function getRolePermissions(string $roleUuid, array $filters = []): array
    {
        $query = $this->db->select($this->table, $this->defaultFields)
            ->where(['role_uuid' => $roleUuid]);

        // Filter active permissions only
        if ($filters['active_only'] ?? false) {
            $currentTime = $this->db->getDriver()->formatDateTime();
            $query->whereGreaterThan('expires_at', $currentTime)->orWhereNull('expires_at');
        }

        $results = $query->get();
        return $this->toModels($results);
    }

    /**
     * Get all roles that have a specific permission
     *
     * @param string $permissionUuid Permission UUID
     * @return array Roles with this permission
     */
    public function getRolesWithPermission(string $permissionUuid): array
    {
        $results = $this->findWhere(['permission_uuid' => $permissionUuid]);
        return $this->toModels($results);
    }

    /**
     * Check if role has specific permission
     *
     * @param string $roleUuid Role UUID
     * @param string $permissionUuid Permission UUID
     * @param array $context Optional context for checking
     * @return bool Has permission
     */
    public function roleHasPermission(string $roleUuid, string $permissionUuid, array $context = []): bool
    {
        $assignments = $this->findWhere([
            'role_uuid' => $roleUuid,
            'permission_uuid' => $permissionUuid
        ]);

        if (empty($assignments)) {
            return false;
        }

        foreach ($assignments as $assignmentData) {
            $assignment = new RolePermission($assignmentData);

            // Check if expired
            if ($assignment->isExpired()) {
                continue;
            }

            // Check resource filter if provided
            if (isset($context['resource'])) {
                $resourceFilter = $assignment->getResourceFilter();
                if (!empty($resourceFilter) && !$this->matchesResourceFilter($resourceFilter, $context['resource'])) {
                    continue;
                }
            }

            // Check constraints if provided
            if (!empty($context)) {
                $constraints = $assignment->getConstraints();
                if (!empty($constraints) && !$this->satisfiesConstraints($constraints, $context)) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Batch assign permissions to role
     *
     * @param string $roleUuid Role UUID
     * @param array $permissionUuids Array of permission UUIDs
     * @param array $options Assignment options
     * @return array Results
     */
    public function batchAssignPermissions(string $roleUuid, array $permissionUuids, array $options = []): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'assignments' => []
        ];

        foreach ($permissionUuids as $permissionUuid) {
            $assignment = $this->assignPermissionToRole($roleUuid, $permissionUuid, $options);
            if ($assignment) {
                $results['success']++;
                $results['assignments'][] = $assignment;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Batch revoke permissions from role
     *
     * @param string $roleUuid Role UUID
     * @param array $permissionUuids Array of permission UUIDs
     * @return array Results
     */
    public function batchRevokePermissions(string $roleUuid, array $permissionUuids): array
    {
        $results = [
            'success' => 0,
            'failed' => 0
        ];

        foreach ($permissionUuids as $permissionUuid) {
            if ($this->revokePermissionFromRole($roleUuid, $permissionUuid)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Replace all permissions for a role
     *
     * @param string $roleUuid Role UUID
     * @param array $permissionUuids New permission UUIDs
     * @param array $options Assignment options
     * @return bool Success status
     */
    public function replaceRolePermissions(string $roleUuid, array $permissionUuids, array $options = []): bool
    {
        // Remove all existing permissions
        $existing = $this->getRolePermissions($roleUuid);
        foreach ($existing as $assignment) {
            $this->delete($assignment->getUuid());
        }

        // Assign new permissions
        $results = $this->batchAssignPermissions($roleUuid, $permissionUuids, $options);

        return $results['failed'] === 0;
    }

    /**
     * Cleanup expired role permissions
     *
     * @return int Number of cleaned permissions
     */
    public function cleanupExpiredPermissions(): int
    {
        $currentTime = $this->db->getDriver()->formatDateTime();
        $query = $this->db->select($this->table, $this->defaultFields)
            ->whereNotNull('expires_at')
            ->whereLessThanOrEqual('expires_at', $currentTime);

        $expired = $query->get();

        $count = 0;
        foreach ($expired as $assignment) {
            if ($this->delete($assignment['uuid'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get role permission statistics
     *
     * @param string $roleUuid Role UUID
     * @return array Statistics
     */
    public function getRolePermissionStats(string $roleUuid): array
    {
        $all = $this->getRolePermissions($roleUuid);
        $active = $this->getRolePermissions($roleUuid, ['active_only' => true]);

        return [
            'total' => count($all),
            'active' => count($active),
            'expired' => count($all) - count($active)
        ];
    }

    // Private helper methods

    /**
     * Convert array data to RolePermission models
     *
     * @param array $data Array of role permission data
     * @return array Array of RolePermission models
     */
    private function toModels(array $data): array
    {
        return array_map(function ($item) {
            return new RolePermission($item);
        }, $data);
    }

    private function matchesResourceFilter(array $filter, string $resource): bool
    {
        if (empty($filter['resource'])) {
            return true; // No filter means all resources
        }

        if ($filter['resource'] === '*') {
            return true; // Wildcard matches all
        }

        // Check exact match
        if ($filter['resource'] === $resource) {
            return true;
        }

        // Check pattern match (e.g., "users.*" matches "users.create")
        $pattern = str_replace('*', '.*', $filter['resource']);
        return (bool) preg_match('/^' . $pattern . '$/', $resource);
    }

    private function satisfiesConstraints(array $constraints, array $context): bool
    {
        foreach ($constraints as $key => $constraint) {
            if (!isset($context[$key])) {
                return false;
            }

            // Handle different constraint types
            if (is_array($constraint)) {
                // Array constraint - value must be in array
                if (!in_array($context[$key], $constraint)) {
                    return false;
                }
            } elseif (is_string($constraint) && strpos($constraint, ':') !== false) {
                // Operator constraint (e.g., ">:100", "<=:50")
                list($operator, $value) = explode(':', $constraint, 2);
                if (!$this->evaluateOperator($context[$key], $operator, $value)) {
                    return false;
                }
            } else {
                // Direct value comparison
                if ($context[$key] != $constraint) {
                    return false;
                }
            }
        }

        return true;
    }

    private function evaluateOperator($contextValue, string $operator, $constraintValue): bool
    {
        switch ($operator) {
            case '>':
                return $contextValue > $constraintValue;
            case '>=':
                return $contextValue >= $constraintValue;
            case '<':
                return $contextValue < $constraintValue;
            case '<=':
                return $contextValue <= $constraintValue;
            case '!=':
                return $contextValue != $constraintValue;
            case 'in':
                return in_array($contextValue, explode(',', $constraintValue));
            case 'not_in':
                return !in_array($contextValue, explode(',', $constraintValue));
            default:
                return false;
        }
    }
}
