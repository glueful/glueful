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

    public function getTableName(): string
    {
        return $this->table;
    }

    public function create(array $data): string
    {
        if (!isset($data['uuid'])) {
            $data['uuid'] = Utils::generateNanoID();
        }

        $success = $this->db->insert($this->table, $data);

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
        $result = $this->findRecordByUuid($uuid, $this->defaultFields);
        return $result ? new Role($result) : null;
    }

    public function findRoleBySlug(string $slug): ?Role
    {
        $result = $this->findBySlug($slug, $this->defaultFields);
        return $result ? new Role($result) : null;
    }

    public function findByName(string $name): ?Role
    {
        $result = $this->db->select($this->table, $this->defaultFields)
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

        return $this->db->update($this->table, $data, ['uuid' => $uuid]);
    }

    public function delete(string $uuid): bool
    {
        return $this->db->delete($this->table, ['uuid' => $uuid]);
    }

    public function softDeleteRole(string $uuid): bool
    {
        return $this->update($uuid, ['deleted_at' => $this->db->getDriver()->formatDateTime()]);
    }

    public function findAllRoles(array $filters = []): array
    {
        $query = $this->db->select($this->table, $this->defaultFields);

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
            $query->where(['deleted_at' => null]);
        }

        $query->orderBy(['level' => 'DESC', 'name' => 'ASC']);

        $results = $query->get();
        return array_map(fn($row) => new Role($row), $results);
    }

    public function findByLevel(int $level): array
    {
        $results = $this->db->select($this->table, $this->defaultFields)
            ->where(['level' => $level])
            ->orderBy(['name' => 'ASC'])
            ->get();

        return array_map(fn($row) => new Role($row), $results);
    }

    public function findChildren(string $parentUuid): array
    {
        $results = $this->db->select($this->table, $this->defaultFields)
            ->where(['parent_uuid' => $parentUuid])
            ->orderBy(['level' => 'ASC', 'name' => 'ASC'])
            ->get();

        return array_map(fn($row) => new Role($row), $results);
    }

    public function findRootRoles(): array
    {
        $results = $this->db->select($this->table, $this->defaultFields)
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
        $query = $this->db->select($this->table, ['uuid'])
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
        $query = $this->db->select($this->table, ['uuid'])
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
        $query = $this->db->select($this->table, ['COUNT(*) as count']);

        if (isset($filters['status'])) {
            $query->where(['status' => $filters['status']]);
        }

        if (isset($filters['is_system'])) {
            $query->where(['is_system' => $filters['is_system']]);
        }

        if (isset($filters['exclude_deleted']) && $filters['exclude_deleted']) {
            $query->where(['deleted_at' => null]);
        }

        $result = $query->get();
        return (int)($result[0]['count'] ?? 0);
    }

    public function findAllPaginated(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        // Build conditions array for the base paginate method
        $conditions = [];

        // Apply filters
        if (isset($filters['exclude_deleted']) && $filters['exclude_deleted']) {
            $conditions['deleted_at'] = null;
        }

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

        // Handle search separately since it needs LIKE queries
        if (isset($filters['search']) && !empty($filters['search'])) {
            $searchTerm = $filters['search'];

            // Use the query method for more complex search
            $query = $this->query()
                ->where([
                    'name' => ['LIKE', '%' . $searchTerm . '%'],
                    'OR' => [
                        'description' => ['LIKE', '%' . $searchTerm . '%'],
                        'slug' => ['LIKE', '%' . $searchTerm . '%']
                    ]
                ]);

            // Add other conditions to the search query
            if (!empty($conditions)) {
                $query->where($conditions);
            }

            $query->orderBy(['level' => 'DESC', 'name' => 'ASC']);

            return $query->paginate($page, $perPage);
        }

        // Use the base repository's paginate method for simple filters
        return $this->paginate(
            $page,
            $perPage,
            $conditions,
            ['level' => 'DESC', 'name' => 'ASC']
        );
    }

    public function getUsersWithRolePaginated(string $roleUuid, int $page = 1, int $perPage = 25): array
    {
        // Build query to get users with this role
        $query = $this->getQueryBuilder()
            ->select('user_roles', ['user_uuid', 'scope', 'granted_by', 'expires_at', 'created_at'])
            ->where(['role_uuid' => $roleUuid]);

        // Use the QueryBuilder's built-in pagination
        $result = $query->paginate($page, $perPage);

        // Transform the result to include user details if needed
        // For now, return the paginated user role assignments
        return $result;
    }

    public function getUsersWithRole(string $roleUuid): array
    {
        $results = $this->getQueryBuilder()
            ->select('user_roles', ['user_uuid'])
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

        $results = $this->db->select($this->table, $this->defaultFields)
            ->whereIn('uuid', $uuids)
            ->whereNull('deleted_at')
            ->get();

        $roles = [];
        foreach ($results as $row) {
            $roles[] = new Role($row);
        }

        return $roles;
    }
}
