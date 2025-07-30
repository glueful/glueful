<?php

namespace Glueful\Extensions\RBAC\Repositories;

use Glueful\Repository\BaseRepository;
use Glueful\Extensions\RBAC\Models\Role;
use Glueful\Helpers\Utils;

/**
 * Role Repository
 *
 * Handles CRUD operations and queries for roles
 *
 * Features:
 * - Hierarchical role management
 * - Role inheritance queries
 * - System role protection
 * - Metadata-based queries
 */
class RoleRepository extends BaseRepository
{
    protected string $table = 'roles';
    protected array $defaultFields = [
        'uuid', 'name', 'slug', 'description', 'parent_uuid',
        'level', 'is_system', 'metadata', 'status',
        'created_at', 'updated_at', 'deleted_at'
    ];

    // Cache to prevent duplicate role lookups within a single request
    private array $rolesCache = [];

    // Static cache to prevent duplicate queries across all instances within a single request
    private static array $globalRolesCache = [];

    public function getTableName(): string
    {
        return $this->table;
    }

    public function create(array $data): string
    {
        if (!isset($data['uuid'])) {
            $data['uuid'] = Utils::generateNanoID();
        }

        $success = $this->db->table($this->table)->insert($data);

        if (!$success) {
            throw new \RuntimeException('Failed to create role');
        }

        return $data['uuid'];
    }

    public function createRole(array $data): ?Role
    {
        $uuid = $this->create($data);
        return $this->findRoleByUuid($uuid);
    }

    public function findRoleByUuid(string $uuid): ?Role
    {
        // Check static cache first (works across all instances)
        if (isset(self::$globalRolesCache[$uuid])) {
            return self::$globalRolesCache[$uuid];
        }

        // Check cache first
        if (isset($this->rolesCache[$uuid])) {
            return $this->rolesCache[$uuid];
        }

        $result = $this->findRecordByUuid($uuid, $this->defaultFields);
        $role = $result ? new Role($result) : null;

        // Cache the result in both caches (including null results to prevent re-querying non-existent roles)
        $this->rolesCache[$uuid] = $role;
        self::$globalRolesCache[$uuid] = $role;

        return $role;
    }

    public function findRoleBySlug(string $slug): ?Role
    {
        $result = $this->findBySlug($slug, $this->defaultFields);
        return $result ? new Role($result) : null;
    }

    public function findByName(string $name): ?Role
    {
        $result = $this->db->table($this->table)
            ->select($this->defaultFields)
            ->where(['name' => $name])
            ->limit(1)
            ->get();

        return $result ? new Role($result[0]) : null;
    }

    public function update(string $uuid, array $data): bool
    {
        if ($this->hasUpdatedAt) {
            $data['updated_at'] = $this->db->getDriver()->formatDateTime();
        }

        return $this->db->table($this->table)->where(['uuid' => $uuid])->update($data);
    }

    public function delete(string $uuid): bool
    {
        return $this->db->table($this->table)->where(['uuid' => $uuid])->delete();
    }

    public function softDeleteRole(string $uuid): bool
    {
        return $this->update($uuid, ['deleted_at' => $this->db->getDriver()->formatDateTime()]);
    }

    public function findAllRoles(array $filters = []): array
    {
        $query = $this->db->table($this->table)->select($this->defaultFields);

        if (isset($filters['status'])) {
            $query->where(['status' => $filters['status']]);
        }

        if (isset($filters['is_system'])) {
            $query->where(['is_system' => $filters['is_system']]);
        }

        if (isset($filters['parent_uuid'])) {
            $query->where(['parent_uuid' => $filters['parent_uuid']]);
        }

        if (isset($filters['exclude_deleted']) && $filters['exclude_deleted']) {
            $query->whereNull('deleted_at');
        }

        $query->orderBy(['level' => 'DESC', 'name' => 'ASC']);

        $results = $query->get();
        return array_map(fn($row) => new Role($row), $results);
    }

    public function findByLevel(int $level): array
    {
        $results = $this->db->table($this->table)
            ->select($this->defaultFields)
            ->where(['level' => $level])
            ->orderBy(['name' => 'ASC'])
            ->get();

        return array_map(fn($row) => new Role($row), $results);
    }

    public function findChildren(string $parentUuid): array
    {
        $results = $this->db->table($this->table)
            ->select($this->defaultFields)
            ->where(['parent_uuid' => $parentUuid])
            ->orderBy(['level' => 'ASC', 'name' => 'ASC'])
            ->get();

        return array_map(fn($row) => new Role($row), $results);
    }

    public function findRootRoles(): array
    {
        $results = $this->db->table($this->table)
            ->select($this->defaultFields)
            ->where(['parent_uuid' => null])
            ->orderBy(['level' => 'DESC', 'name' => 'ASC'])
            ->get();

        return array_map(fn($row) => new Role($row), $results);
    }

    public function getRoleHierarchy(string $roleUuid): array
    {
        $hierarchy = [];
        $currentRole = $this->findRoleByUuid($roleUuid);

        while ($currentRole && !in_array($currentRole->getUuid(), array_column($hierarchy, 'uuid'))) {
            $hierarchy[] = $currentRole;

            if ($currentRole->hasParent()) {
                $currentRole = $this->findRoleByUuid($currentRole->getParentUuid());
            } else {
                break;
            }
        }

        return $hierarchy;
    }

    public function findSystemRoles(): array
    {
        return $this->findAllRoles(['is_system' => 1, 'exclude_deleted' => true]);
    }

    public function findActiveRoles(): array
    {
        return $this->findAllRoles(['status' => 'active', 'exclude_deleted' => true]);
    }

    public function roleExists(string $name, ?string $excludeUuid = null): bool
    {
        $query = $this->db->table($this->table)
            ->select(['uuid'])
            ->where(['name' => $name])
            ->limit(1);

        if ($excludeUuid) {
            $query->where(['uuid' => ['!=', $excludeUuid]]);
        }

        $result = $query->get();
        return !empty($result);
    }

    public function slugExists(string $slug, ?string $excludeUuid = null): bool
    {
        $query = $this->db->table($this->table)
            ->select(['uuid'])
            ->where(['slug' => $slug])
            ->limit(1);

        if ($excludeUuid) {
            $query->where(['uuid' => ['!=', $excludeUuid]]);
        }

        $result = $query->get();
        return !empty($result);
    }

    public function countRoles(array $filters = []): int
    {
        $query = $this->db->table($this->table);

        if (isset($filters['status'])) {
            $query->where(['status' => $filters['status']]);
        }

        if (isset($filters['is_system'])) {
            $query->where(['is_system' => $filters['is_system']]);
        }

        if (isset($filters['exclude_deleted']) && $filters['exclude_deleted']) {
            $query->whereNull('deleted_at');
        }

        return $query->count();
    }

    public function findAllPaginated(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        // Build conditions array for the base paginate method
        $conditions = [];

        // Apply filters
        $excludeDeleted = isset($filters['exclude_deleted']) && $filters['exclude_deleted'];

        if (isset($filters['status']) && !empty($filters['status'])) {
            $conditions['status'] = $filters['status'];
        }

        if (isset($filters['is_system'])) {
            $conditions['is_system'] = $filters['is_system'];
        }

        if (isset($filters['level'])) {
            $conditions['level'] = $filters['level'];
        }

        if (isset($filters['parent_uuid'])) {
            $conditions['parent_uuid'] = $filters['parent_uuid'];
        }

        // Build query using QueryBuilder to handle NULL conditions properly
        $query = $this->db->table($this->table)->select($this->defaultFields);

        // Apply deleted_at filter using whereNull
        if ($excludeDeleted) {
            $query->whereNull('deleted_at');
        }

        // Add other conditions
        if (!empty($conditions)) {
            $query->where($conditions);
        }

        // Handle search separately since it needs LIKE queries
        if (isset($filters['search']) && !empty($filters['search'])) {
            $searchTerm = $filters['search'];
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', '%' . $searchTerm . '%')
                  ->orWhere('description', 'LIKE', '%' . $searchTerm . '%')
                  ->orWhere('slug', 'LIKE', '%' . $searchTerm . '%');
            });
        }

        $query->orderBy(['level' => 'DESC', 'name' => 'ASC']);

        $result = $query->paginate($page, $perPage);

        // Convert Role objects to arrays
        if (!empty($result['data'])) {
            $roles = [];
            foreach ($result['data'] as $row) {
                $role = new Role($row);
                $roles[] = $role->toArray();
            }
            $result['data'] = $roles;
        }

        return $result;
    }

    public function getUsersWithRolePaginated(string $roleUuid, int $page = 1, int $perPage = 25): array
    {
        // Build query to get users with this role
        $query = $this->db->table('user_roles')
            ->select(['user_uuid', 'scope', 'granted_by', 'expires_at', 'created_at'])
            ->where(['role_uuid' => $roleUuid]);

        // Use the QueryBuilder's built-in pagination
        $result = $query->paginate($page, $perPage);

        // Transform the result to include user details if needed
        // For now, return the paginated user role assignments
        return $result;
    }

    public function getUsersWithRole(string $roleUuid): array
    {
        $results = $this->db->table('user_roles')
            ->select(['user_uuid'])
            ->where(['role_uuid' => $roleUuid])
            ->get();

        return array_column($results, 'user_uuid');
    }

    /**
     * Find roles by multiple UUIDs efficiently
     *
     * @param array $uuids Array of role UUIDs
     * @return array Array of Role objects
     */
    public function findByUuids(array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        $roles = [];
        $uncachedUuids = [];

        // First, check cache for each UUID
        foreach ($uuids as $uuid) {
            if (isset(self::$globalRolesCache[$uuid])) {
                $role = self::$globalRolesCache[$uuid];
                if ($role !== null) {
                    $roles[] = $role;
                }
            } else {
                $uncachedUuids[] = $uuid;
            }
        }

        // If all roles were found in cache, return them
        if (empty($uncachedUuids)) {
            return $roles;
        }

        // Fetch uncached roles from database
        $results = $this->db->table($this->table)
            ->select($this->defaultFields)
            ->whereIn('uuid', $uncachedUuids)
            ->whereNull('deleted_at')
            ->get();

        // Process results and add to cache
        foreach ($results as $row) {
            $role = new Role($row);
            $roles[] = $role;

            // Cache in both instance and global cache
            $this->rolesCache[$role->getUuid()] = $role;
            self::$globalRolesCache[$role->getUuid()] = $role;
        }

        // Mark non-existent UUIDs as null in cache to prevent re-querying
        foreach ($uncachedUuids as $uuid) {
            $found = false;
            foreach ($results as $row) {
                if ($row['uuid'] === $uuid) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                self::$globalRolesCache[$uuid] = null;
            }
        }

        return $roles;
    }
}
