<?php

namespace Glueful\Extensions\RBAC\Repositories;

use Glueful\Repository\BaseRepository;
use Glueful\Extensions\RBAC\Models\Permission;
use Glueful\Helpers\Utils;

/**
 * Permission Repository
 *
 * Handles CRUD operations and queries for permissions
 *
 * Features:
 * - Category-based permission organization
 * - Resource type filtering
 * - System permission protection
 * - Metadata-based queries
 */
class PermissionRepository extends BaseRepository
{
    protected string $table = 'permissions';
    protected array $defaultFields = [
        'uuid', 'name', 'slug', 'description', 'category',
        'resource_type', 'is_system', 'metadata', 'created_at'
    ];
    protected bool $hasUpdatedAt = false;

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
            throw new \RuntimeException('Failed to create permission');
        }

        return $data['uuid'];
    }

    public function createPermission(array $data): ?Permission
    {
        $uuid = $this->create($data);
        return $this->findPermissionByUuid($uuid);
    }

    public function findPermissionByUuid(string $uuid): ?Permission
    {
        $result = $this->findRecordByUuid($uuid, $this->defaultFields);
        return $result ? new Permission($result) : null;
    }

    public function findPermissionBySlug(string $slug): ?Permission
    {
        $result = $this->findBySlug($slug, $this->defaultFields);
        return $result ? new Permission($result) : null;
    }

    public function findByName(string $name): ?Permission
    {
        $result = $this->db->select($this->table, $this->defaultFields)
            ->where(['name' => $name])
            ->limit(1)
            ->get();

        return $result ? new Permission($result[0]) : null;
    }

    public function update(string $uuid, array $data): bool
    {
        return $this->db->update($this->table, $data, ['uuid' => $uuid]);
    }

    public function delete(string $uuid): bool
    {
        return $this->db->delete($this->table, ['uuid' => $uuid]);
    }

    public function findAllPermissions(array $filters = []): array
    {
        $query = $this->db->select($this->table, $this->defaultFields);

        if (isset($filters['category'])) {
            $query->where(['category' => $filters['category']]);
        }

        if (isset($filters['resource_type'])) {
            $query->where(['resource_type' => $filters['resource_type']]);
        }

        if (isset($filters['is_system'])) {
            $query->where(['is_system' => $filters['is_system']]);
        }

        $query->orderBy(['category' => 'ASC', 'name' => 'ASC']);

        $results = $query->get();
        return array_map(fn($row) => new Permission($row), $results);
    }

    public function findByCategory(string $category): array
    {
        $results = $this->db->select($this->table, $this->defaultFields)
            ->where(['category' => $category])
            ->orderBy(['name' => 'ASC'])
            ->get();

        return array_map(fn($row) => new Permission($row), $results);
    }

    public function findByResourceType(string $resourceType): array
    {
        $results = $this->db->select($this->table, $this->defaultFields)
            ->where(['resource_type' => $resourceType])
            ->orderBy(['category' => 'ASC', 'name' => 'ASC'])
            ->get();

        return array_map(fn($row) => new Permission($row), $results);
    }

    public function findSystemPermissions(): array
    {
        return $this->findAllPermissions(['is_system' => 1]);
    }

    public function getCategories(): array
    {
        $results = $this->db->select($this->table, ['DISTINCT category'])
            ->where(['category' => ['!=', null]])
            ->orderBy(['category' => 'ASC'])
            ->get();

        return array_column($results, 'category');
    }

    public function getResourceTypes(): array
    {
        $results = $this->db->select($this->table, ['DISTINCT resource_type'])
            ->where(['resource_type' => ['!=', null]])
            ->orderBy(['resource_type' => 'ASC'])
            ->get();

        return array_column($results, 'resource_type');
    }

    public function permissionExists(string $name, ?string $excludeUuid = null): bool
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

    public function countPermissions(array $filters = []): int
    {
        $query = $this->db->select($this->table, ['COUNT(*) as count']);

        if (isset($filters['category'])) {
            $query->where(['category' => $filters['category']]);
        }

        if (isset($filters['resource_type'])) {
            $query->where(['resource_type' => $filters['resource_type']]);
        }

        if (isset($filters['is_system'])) {
            $query->where(['is_system' => $filters['is_system']]);
        }

        $result = $query->get();
        return (int)($result[0]['count'] ?? 0);
    }

    public function searchPermissions(string $searchTerm, array $filters = []): array
    {
        $query = $this->db->select($this->table, $this->defaultFields);

        // Add search conditions
        $query->where([
            'name' => ['LIKE', '%' . $searchTerm . '%'],
            'OR' => [
                'description' => ['LIKE', '%' . $searchTerm . '%'],
                'slug' => ['LIKE', '%' . $searchTerm . '%']
            ]
        ]);

        // Apply additional filters
        if (isset($filters['category'])) {
            $query->where(['category' => $filters['category']]);
        }

        if (isset($filters['resource_type'])) {
            $query->where(['resource_type' => $filters['resource_type']]);
        }

        if (isset($filters['is_system'])) {
            $query->where(['is_system' => $filters['is_system']]);
        }

        $query->orderBy(['category' => 'ASC', 'name' => 'ASC']);

        $results = $query->get();
        return array_map(fn($row) => new Permission($row), $results);
    }

    public function findAllPaginated(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        // Build conditions array for the base paginate method
        $conditions = [];

        // Apply filters
        // Note: permissions table doesn't have soft deletes, so exclude_deleted filter is ignored

        if (isset($filters['category']) && !empty($filters['category'])) {
            $conditions['category'] = $filters['category'];
        }

        if (isset($filters['resource_type']) && !empty($filters['resource_type'])) {
            $conditions['resource_type'] = $filters['resource_type'];
        }

        if (isset($filters['is_system'])) {
            $conditions['is_system'] = $filters['is_system'];
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

            $query->orderBy(['category' => 'ASC', 'name' => 'ASC']);

            return $query->paginate($page, $perPage);
        }

        // Use the base repository's paginate method for simple filters
        return $this->paginate(
            $page,
            $perPage,
            $conditions,
            ['category' => 'ASC', 'name' => 'ASC']
        );
    }

    public function getUsersWithPermission(string $permissionUuid): array
    {
        $results = $this->db->select('user_permissions', ['user_uuid'])
            ->where(['permission_uuid' => $permissionUuid])
            ->get();

        return array_column($results, 'user_uuid');
    }

    /**
     * Find permissions by multiple UUIDs efficiently
     *
     * @param array $uuids Array of permission UUIDs
     * @return array Array of Permission objects
     */
    public function findByUuids(array $uuids): array
    {
        if (empty($uuids)) {
            return [];
        }

        $results = $this->db->select($this->table, $this->defaultFields)
            ->whereIn('uuid', $uuids)
            ->get();

        $permissions = [];
        foreach ($results as $row) {
            $permissions[] = new Permission($row);
        }

        return $permissions;
    }
}
