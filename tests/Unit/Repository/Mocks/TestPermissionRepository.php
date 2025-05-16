<?php
namespace Tests\Unit\Repository\Mocks;

use Glueful\Repository\PermissionRepository;
use Glueful\Repository\RoleRepository;
use Glueful\Database\QueryBuilder;

/**
 * Test Permission Repository
 *
 * Extends the real PermissionRepository but allows dependency injection
 * for easier testing.
 */
class TestPermissionRepository extends PermissionRepository
{
    /**
     * @var QueryBuilder Direct access to query builder for test methods
     */
    protected QueryBuilder $testDb;

    /**
     * @var RoleRepository|null Role repository instance for testing
     */
    protected ?RoleRepository $roleRepository;

    /**
     * Constructor with dependency injection support
     *
     * @param QueryBuilder|null $queryBuilder Optional query builder to inject
     * @param RoleRepository|null $roleRepository Optional role repository to inject
     */
    public function __construct(?QueryBuilder $queryBuilder = null, ?RoleRepository $roleRepository = null)
    {
        // Skip parent constructor to avoid real database connection

        if ($queryBuilder) {
            // Use reflection to set the private property in the parent class
            $reflection = new \ReflectionClass(PermissionRepository::class);
            $dbProperty = $reflection->getProperty('db');
            $dbProperty->setAccessible(true);
            $dbProperty->setValue($this, $queryBuilder);

            // Also save reference for our test methods
            $this->testDb = $queryBuilder;

            // Save role repository for later use
            $this->roleRepository = $roleRepository;
        } else {
            // Create mock connection with in-memory SQLite
            $connection = new MockPermissionConnection();
            $queryBuilder = new QueryBuilder($connection->getPDO(), $connection->getDriver());

            // Use reflection to set the private property in the parent class
            $reflection = new \ReflectionClass(PermissionRepository::class);
            $dbProperty = $reflection->getProperty('db');
            $dbProperty->setAccessible(true);
            $dbProperty->setValue($this, $queryBuilder);

            // Also save reference for our test methods
            $this->testDb = $queryBuilder;

            // Save role repository
            $this->roleRepository = $roleRepository ?? new TestRoleRepository($queryBuilder);
        }
    }

    /**
     * Override assignRolePermission to ensure it returns a valid UUID
     *
     * @param string $roleUuid Role UUID to assign permission to
     * @param string $model Model name to set permission for
     * @param mixed $permissions Permission string in format ABDC
     * @return string|bool UUID of the new permission or false on failure
     */
    public function assignRolePermission(string $roleUuid, string $model, $permissions): string|bool
    {
        $uuid = \Glueful\Helpers\Utils::generateNanoID();

        $data = [
            'uuid' => $uuid,
            'role_uuid' => $roleUuid,
            'model' => $model,
            'permissions' => $permissions
        ];

        $result = $this->testDb->insert('role_permissions', $data);

        return $result ? $uuid : false;
    }

    /**
     * Override removeRolePermission to avoid dependencies
     *
     * @param string $roleUuid Role UUID to remove permission from
     * @param string $model Model name to remove permission for
     * @return bool Success status
     */
    public function removeRolePermission(string $roleUuid, string $model): bool
    {
        $result = $this->testDb->delete('role_permissions', [
            'role_uuid' => $roleUuid,
            'model' => $model
        ]);

        return $result ? true : false;
    }

    /**
     * Override hasPermission to use our injected role repository
     *
     * @param string $userUuid User UUID to check permission for
     * @param string $model Model name/resource to check access for
     * @param string $permission Permission to check
     * @return bool True if user has the permission
     */
    public function hasPermission(string $userUuid, string $model, string $permission): bool
    {
        // First check user-specific permissions (override)
        $userPerms = $this->getUserPermissions($userUuid);
        if (isset($userPerms[$model])) {
            return in_array($permission, $userPerms[$model]);
        }

        // If no user-specific permission, check role permissions
        $roles = $this->roleRepository->getUserRoles($userUuid);

        foreach ($roles as $role) {
            $roleUuid = $role['role_uuid'];
            $rolePerms = $this->roleRepository->getRolePermissions($roleUuid);

            if (isset($rolePerms[$model])) {
                if (strpos($rolePerms[$model]['permissions'], $permission) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Override getEffectivePermissions to use our injected role repository
     *
     * @param string $userUuid User UUID to get permissions for
     * @return array Complete set of user's effective permissions
     */
    public function getEffectivePermissions(string $userUuid): array
    {
        // Get user-specific permissions (overrides)
        $userPerms = $this->getUserPermissions($userUuid);

        // Get all user roles
        $roles = $this->roleRepository->getUserRoles($userUuid);

        // Collect role permissions
        $rolePerms = [];
        foreach ($roles as $role) {
            $roleUuid = $role['role_uuid'];
            $permissions = $this->getRolePermissions($roleUuid);

            foreach ($permissions as $model => $permData) {
                if (!isset($rolePerms[$model])) {
                    $rolePerms[$model] = [];
                }

                // Get permissions as array of characters
                $perms = str_split($permData['permissions']);

                // Merge permissions for this model from this role
                $rolePerms[$model] = array_unique(
                    array_merge($rolePerms[$model], $perms)
                );
            }
        }

        // Merge with user permissions (user permissions take precedence)
        $effectivePerms = $rolePerms;
        foreach ($userPerms as $model => $perms) {
            $effectivePerms[$model] = $perms;
        }

        return $effectivePerms;
    }

    /**
     * Override getRolePermissions to ensure formatted output
     *
     * @param string $roleUuid Role UUID to get permissions for
     * @return array Role permissions, organized by model
     */
    public function getRolePermissions(string $roleUuid): array
    {
        $permissions = $this->testDb->select('role_permissions', [
                'uuid',
                'role_uuid',
                'model',
                'permissions',
                'created_at',
                'updated_at'
            ])
            ->where(['role_uuid' => $roleUuid])
            ->get();

        // Process permissions for easier use
        $formattedPermissions = [];
        foreach ($permissions as $permission) {
            $model = $permission['model'];
            $perms = $permission['permissions'];

            // No need to decode perms as it's already a string

            $formattedPermissions[$model] = [
                'permissions' => $perms,
                'created_at' => $permission['created_at'] ?? null,
                'updated_at' => $permission['updated_at'] ?? null
            ];
        }

        return $formattedPermissions;
    }
}
