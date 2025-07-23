<?php

namespace Glueful\Extensions\RBAC\Services;

use Glueful\Extensions\RBAC\Repositories\RoleRepository;
use Glueful\Extensions\RBAC\Repositories\UserRoleRepository;
use Glueful\Extensions\RBAC\Models\Role;
use Glueful\Helpers\Utils;

/**
 * Role Service
 *
 * Business logic for role management operations
 *
 * Features:
 * - Role CRUD operations with validation
 * - Hierarchical role management
 * - Role assignment to users
 * - Role inheritance validation
 * - System role protection
 */
class RoleService
{
    private RoleRepository $roleRepository;
    private UserRoleRepository $userRoleRepository;

    public function __construct(RoleRepository $roleRepository, UserRoleRepository $userRoleRepository)
    {
        $this->roleRepository = $roleRepository;
        $this->userRoleRepository = $userRoleRepository;
    }

    /**
     * Create a new role
     */
    public function createRole(array $data, string $createdBy = null): ?Role
    {
        // Validate required fields
        if (empty($data['name']) || empty($data['slug'])) {
            throw new \InvalidArgumentException('Role name and slug are required');
        }

        // Check for duplicate name or slug
        if ($this->roleRepository->roleExists($data['name'])) {
            throw new \InvalidArgumentException('Role name already exists');
        }

        if ($this->roleRepository->slugExists($data['slug'])) {
            throw new \InvalidArgumentException('Role slug already exists');
        }

        // Validate parent role if specified
        if (!empty($data['parent_uuid'])) {
            $parentRole = $this->roleRepository->findByUuid($data['parent_uuid']);
            if (!$parentRole) {
                throw new \InvalidArgumentException('Parent role not found');
            }

            // Calculate level based on parent
            $data['level'] = $parentRole->getLevel() + 1;

            // Check for circular reference
            if ($this->wouldCreateCircularReference($data['parent_uuid'], $data['uuid'] ?? '')) {
                throw new \InvalidArgumentException('Circular role hierarchy detected');
            }
        }

        // Set defaults
        $data['uuid'] = $data['uuid'] ?? Utils::generateNanoID();
        $data['level'] = $data['level'] ?? 0;
        $data['is_system'] = $data['is_system'] ?? false;
        $data['status'] = $data['status'] ?? 'active';
        $data['metadata'] = isset($data['metadata']) ? json_encode($data['metadata']) : null;

        return $this->roleRepository->createRole($data);
    }

    /**
     * Update an existing role
     */
    public function updateRole(string $uuid, array $data, string $updatedBy = null): bool
    {
        $role = $this->roleRepository->findByUuid($uuid);
        if (!$role) {
            throw new \InvalidArgumentException('Role not found');
        }

        // Protect system roles from certain changes
        if ($role->isSystem()) {
            $protectedFields = ['is_system', 'slug'];
            foreach ($protectedFields as $field) {
                if (isset($data[$field]) && $data[$field] !== $role->toArray()[$field]) {
                    throw new \InvalidArgumentException("Cannot modify {$field} for system roles");
                }
            }
        }

        // Validate name uniqueness if changed
        if (isset($data['name']) && $data['name'] !== $role->getName()) {
            if ($this->roleRepository->roleExists($data['name'], $uuid)) {
                throw new \InvalidArgumentException('Role name already exists');
            }
        }

        // Validate slug uniqueness if changed
        if (isset($data['slug']) && $data['slug'] !== $role->getSlug()) {
            if ($this->roleRepository->slugExists($data['slug'], $uuid)) {
                throw new \InvalidArgumentException('Role slug already exists');
            }
        }

        // Handle parent changes
        if (isset($data['parent_uuid'])) {
            if ($data['parent_uuid'] !== $role->getParentUuid()) {
                // Validate new parent
                if (!empty($data['parent_uuid'])) {
                    $parentRole = $this->roleRepository->findByUuid($data['parent_uuid']);
                    if (!$parentRole) {
                        throw new \InvalidArgumentException('Parent role not found');
                    }

                    // Check for circular reference
                    if ($this->wouldCreateCircularReference($data['parent_uuid'], $uuid)) {
                        throw new \InvalidArgumentException('Circular role hierarchy detected');
                    }

                    $data['level'] = $parentRole->getLevel() + 1;
                } else {
                    $data['level'] = 0;
                }

                // Update child role levels
                $this->updateChildRoleLevels($uuid);
            }
        }

        // Encode metadata if provided
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata']);
        }

        return $this->roleRepository->update($uuid, $data);
    }

    /**
     * Delete a role
     */
    public function deleteRole(string $uuid, bool $force = false): bool
    {
        $role = $this->roleRepository->findByUuid($uuid);
        if (!$role) {
            throw new \InvalidArgumentException('Role not found');
        }

        // Protect system roles
        if ($role->isSystem() && !$force) {
            throw new \InvalidArgumentException('Cannot delete system roles');
        }

        // Check for role assignments
        $userCount = count($this->userRoleRepository->findByRole($uuid));
        if ($userCount > 0 && !$force) {
            throw new \InvalidArgumentException("Cannot delete role: {$userCount} users still assigned");
        }

        // Check for child roles
        $children = $this->roleRepository->findChildren($uuid);
        if (!empty($children) && !$force) {
            throw new \InvalidArgumentException('Cannot delete role: has child roles');
        }

        // If force delete, handle dependencies
        if ($force) {
            // Remove all user assignments
            foreach ($this->userRoleRepository->findByRole($uuid) as $userRole) {
                $this->userRoleRepository->delete($userRole->getUuid());
            }

            // Update child roles to remove parent
            foreach ($children as $child) {
                $this->roleRepository->update($child->getUuid(), [
                    'parent_uuid' => null,
                    'level' => 0
                ]);
            }
        }

        return $this->roleRepository->delete($uuid);
    }

    /**
     * Assign role to user
     */
    public function assignRoleToUser(string $userUuid, string $roleUuid, array $options = []): bool
    {
        $role = $this->roleRepository->findByUuid($roleUuid);
        if (!$role) {
            throw new \InvalidArgumentException('Role not found');
        }

        if (!$role->isActive()) {
            throw new \InvalidArgumentException('Cannot assign inactive role');
        }

        // Check if already assigned
        $scope = $options['scope'] ?? [];
        if ($this->userRoleRepository->hasUserRole($userUuid, $roleUuid, $scope)) {
            return true; // Already assigned
        }

        $assignment = $this->userRoleRepository->assignRole($userUuid, $roleUuid, $options);
        return $assignment !== null;
    }

    /**
     * Revoke role from user
     */
    public function revokeRoleFromUser(string $userUuid, string $roleUuid): bool
    {
        return $this->userRoleRepository->revokeRole($userUuid, $roleUuid);
    }

    /**
     * Get role hierarchy
     */
    public function getRoleHierarchy(string $roleUuid): array
    {
        return $this->roleRepository->getRoleHierarchy($roleUuid);
    }

    /**
     * Get all roles with their hierarchy
     */
    public function getRoleTree(): array
    {
        $allRoles = $this->roleRepository->findAll(['exclude_deleted' => true]);
        return $this->buildRoleTree($allRoles);
    }

    /**
     * Get user roles
     */
    public function getUserRoles(string $userUuid, array $scope = []): array
    {
        $userRoles = $this->userRoleRepository->getUserRoles($userUuid, $scope);
        $roles = [];

        foreach ($userRoles as $userRole) {
            $role = $this->roleRepository->findByUuid($userRole->getRoleUuid());
            if ($role) {
                $roles[] = [
                    'role' => $role,
                    'assignment' => $userRole
                ];
            }
        }

        return $roles;
    }

    /**
     * Check if user has role
     */
    public function userHasRole(string $userUuid, string $roleSlug, array $scope = []): bool
    {
        $role = $this->roleRepository->findBySlug($roleSlug);
        if (!$role) {
            return false;
        }

        return $this->userRoleRepository->hasUserRole($userUuid, $role->getUuid(), $scope);
    }

    // Private helper methods

    private function wouldCreateCircularReference(string $parentUuid, string $childUuid): bool
    {
        if (empty($childUuid) || $parentUuid === $childUuid) {
            return true;
        }

        $hierarchy = $this->roleRepository->getRoleHierarchy($parentUuid);
        foreach ($hierarchy as $role) {
            if ($role->getUuid() === $childUuid) {
                return true;
            }
        }

        return false;
    }

    private function updateChildRoleLevels(string $parentUuid): void
    {
        $parentRole = $this->roleRepository->findByUuid($parentUuid);
        if (!$parentRole) {
            return;
        }

        $children = $this->roleRepository->findChildren($parentUuid);
        foreach ($children as $child) {
            $newLevel = $parentRole->getLevel() + 1;
            $this->roleRepository->update($child->getUuid(), ['level' => $newLevel]);

            // Recursively update grandchildren
            $this->updateChildRoleLevels($child->getUuid());
        }
    }

    private function buildRoleTree(array $roles): array
    {
        $tree = [];
        $roleMap = [];

        // Create a map for quick lookup
        foreach ($roles as $role) {
            $roleMap[$role->getUuid()] = [
                'role' => $role,
                'children' => []
            ];
        }

        // Build the tree structure
        foreach ($roles as $role) {
            if ($role->hasParent() && isset($roleMap[$role->getParentUuid()])) {
                $roleMap[$role->getParentUuid()]['children'][] = &$roleMap[$role->getUuid()];
            } else {
                $tree[] = &$roleMap[$role->getUuid()];
            }
        }

        return $tree;
    }

    /**
     * Get roles for multiple users efficiently (avoids N+1 queries)
     *
     * @param array $userUuids Array of user UUIDs
     * @param array $scope Optional scope filter
     * @return array Associative array with user_uuid as key and array of roles as value
     */
    public function getBulkUserRoles(array $userUuids, array $scope = []): array
    {
        if (empty($userUuids)) {
            return [];
        }

        // Get all user roles for the given users
        $allUserRoles = $this->userRoleRepository->getBulkUserRoles($userUuids, $scope);

        // Extract unique role UUIDs
        $roleUuids = array_unique(array_map(function ($userRole) {
            return $userRole->getRoleUuid();
        }, $allUserRoles));

        // Fetch all roles in a single query
        $rolesMap = [];
        if (!empty($roleUuids)) {
            $roles = $this->roleRepository->findByUuids($roleUuids);
            foreach ($roles as $role) {
                $rolesMap[$role->getUuid()] = $role;
            }
        }

        // Group user roles by user UUID and attach role data
        $result = [];
        foreach ($allUserRoles as $userRole) {
            $userUuid = $userRole->getUserUuid();
            $roleUuid = $userRole->getRoleUuid();

            if (isset($rolesMap[$roleUuid])) {
                if (!isset($result[$userUuid])) {
                    $result[$userUuid] = [];
                }

                $result[$userUuid][] = [
                    'role' => $rolesMap[$roleUuid],
                    'assignment' => $userRole
                ];
            }
        }

        // Ensure all requested users have an entry (even if empty)
        foreach ($userUuids as $userUuid) {
            if (!isset($result[$userUuid])) {
                $result[$userUuid] = [];
            }
        }

        return $result;
    }
}
