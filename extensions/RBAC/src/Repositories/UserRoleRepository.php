<?php

namespace Glueful\Extensions\RBAC\Repositories;

use Glueful\Repository\BaseRepository;
use Glueful\Extensions\RBAC\Models\UserRole;
use Glueful\Helpers\Utils;

/**
 * User Role Repository
 *
 * Handles user-role assignments and queries
 *
 * Features:
 * - Scoped role assignments
 * - Temporal role management
 * - Role hierarchy traversal
 * - Assignment tracking
 */
class UserRoleRepository extends BaseRepository
{
    protected string $table = 'user_roles';
    protected array $defaultFields = [
        'uuid', 'user_uuid', 'role_uuid', 'scope',
        'granted_by', 'expires_at', 'created_at'
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
            throw new \RuntimeException('Failed to create user role');
        }

        return $data['uuid'];
    }

    public function createUserRole(array $data): ?UserRole
    {
        $uuid = $this->create($data);
        return $this->findByUuid($uuid);
    }

    public function findByUuid(string $uuid): ?UserRole
    {
        $result = $this->db->select($this->table, $this->defaultFields)
            ->where(['uuid' => $uuid])
            ->limit(1)
            ->get();

        return $result ? new UserRole($result[0]) : null;
    }

    public function update(string $uuid, array $data): bool
    {
        return $this->db->update($this->table, $data, ['uuid' => $uuid]);
    }

    public function delete(string $uuid): bool
    {
        return $this->db->delete($this->table, ['uuid' => $uuid]);
    }

    public function findByUser(string $userUuid, array $filters = []): array
    {
        $query = $this->db->select($this->table, $this->defaultFields)
            ->where(['user_uuid' => $userUuid]);

        if (isset($filters['role_uuid'])) {
            $query->where(['role_uuid' => $filters['role_uuid']]);
        }

        if (isset($filters['active_only']) && $filters['active_only']) {
            $query->whereRaw("(expires_at IS NULL OR expires_at >= ?)", [date('Y-m-d H:i:s')]);
        }

        $query->orderBy(['created_at' => 'DESC']);

        $results = $query->get();
        return array_map(fn($row) => new UserRole($row), $results);
    }

    public function findByRole(string $roleUuid): array
    {
        $results = $this->db->select($this->table, $this->defaultFields)
            ->where(['role_uuid' => $roleUuid])
            ->orderBy(['created_at' => 'DESC'])
            ->get();

        return array_map(fn($row) => new UserRole($row), $results);
    }

    public function findUserRole(string $userUuid, string $roleUuid): ?UserRole
    {
        $result = $this->db->select($this->table, $this->defaultFields)
            ->where([
                'user_uuid' => $userUuid,
                'role_uuid' => $roleUuid
            ])
            ->limit(1)
            ->get();

        return $result ? new UserRole($result[0]) : null;
    }

    public function hasUserRole(string $userUuid, string $roleUuid, array $scope = []): bool
    {
        $query = $this->db->select($this->table, ['uuid'])
            ->where([
                'user_uuid' => $userUuid,
                'role_uuid' => $roleUuid
            ]);

        // Check if role is still active (not expired)
        $query->whereRaw("(expires_at IS NULL OR expires_at >= ?)", [date('Y-m-d H:i:s')]);

        $results = $query->get();

        if (empty($results)) {
            return false;
        }

        // If we have scope requirements, check scope matching
        if (!empty($scope)) {
            foreach ($results as $row) {
                $userRole = new UserRole($row);
                if ($userRole->matchesScope($scope)) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    public function getUserRoles(string $userUuid, array $scope = []): array
    {
        $query = $this->db->select($this->table, $this->defaultFields)
            ->where(['user_uuid' => $userUuid]);

        // Only get active (non-expired) roles
        $query->whereRaw("(expires_at IS NULL OR expires_at >= ?)", [date('Y-m-d H:i:s')]);

        $results = $query->get();
        $userRoles = array_map(fn($row) => new UserRole($row), $results);

        // Filter by scope if provided
        if (!empty($scope)) {
            $userRoles = array_filter($userRoles, function ($role) use ($scope) {
                return $role->matchesScope($scope);
            });
        }

        return array_values($userRoles);
    }

    public function getUserRoleUuids(string $userUuid, array $scope = []): array
    {
        $userRoles = $this->getUserRoles($userUuid, $scope);
        return array_map(fn($role) => $role->getRoleUuid(), $userRoles);
    }

    public function assignRole(string $userUuid, string $roleUuid, array $options = []): ?UserRole
    {
        // Check if assignment already exists
        if ($this->hasUserRole($userUuid, $roleUuid, $options['scope'] ?? [])) {
            return $this->findUserRole($userUuid, $roleUuid);
        }

        $data = [
            'user_uuid' => $userUuid,
            'role_uuid' => $roleUuid,
            'granted_by' => $options['granted_by'] ?? null,
            'expires_at' => $options['expires_at'] ?? null
        ];

        if (isset($options['scope'])) {
            $data['scope'] = json_encode($options['scope']);
        }

        return $this->createUserRole($data);
    }

    public function revokeRole(string $userUuid, string $roleUuid): bool
    {
        return $this->db->delete($this->table, [
            'user_uuid' => $userUuid,
            'role_uuid' => $roleUuid
        ]);
    }

    public function revokeAllUserRoles(string $userUuid): bool
    {
        return $this->db->delete($this->table, ['user_uuid' => $userUuid]);
    }

    public function findExpiredRoles(): array
    {
        $results = $this->db->select($this->table, $this->defaultFields)
            ->where(['expires_at' => ['<', date('Y-m-d H:i:s')]])
            ->orderBy(['expires_at' => 'ASC'])
            ->get();

        return array_map(fn($row) => new UserRole($row), $results);
    }

    public function cleanupExpiredRoles(): int
    {
        $expiredCount = $this->db->select($this->table, ['COUNT(*) as count'])
            ->where(['expires_at' => ['<', date('Y-m-d H:i:s')]])
            ->get();

        $count = (int)($expiredCount[0]['count'] ?? 0);

        if ($count > 0) {
            $this->db->delete($this->table, ['expires_at' => ['<', date('Y-m-d H:i:s')]]);
        }

        return $count;
    }

    public function findByGrantedBy(string $grantedByUuid): array
    {
        $results = $this->db->select($this->table, $this->defaultFields)
            ->where(['granted_by' => $grantedByUuid])
            ->orderBy(['created_at' => 'DESC'])
            ->get();

        return array_map(fn($row) => new UserRole($row), $results);
    }

    public function getUsersWithRole(string $roleUuid, array $filters = []): array
    {
        $query = $this->db->select($this->table, ['DISTINCT user_uuid'])
            ->where(['role_uuid' => $roleUuid]);

        if (isset($filters['active_only']) && $filters['active_only']) {
            $query->whereRaw("(expires_at IS NULL OR expires_at >= ?)", [date('Y-m-d H:i:s')]);
        }

        $results = $query->get();
        return array_column($results, 'user_uuid');
    }

    public function countUserRoles(string $userUuid, array $filters = []): int
    {
        $query = $this->db->select($this->table, ['COUNT(*) as count'])
            ->where(['user_uuid' => $userUuid]);

        if (isset($filters['active_only']) && $filters['active_only']) {
            $query->whereRaw("(expires_at IS NULL OR expires_at >= ?)", [date('Y-m-d H:i:s')]);
        }

        $result = $query->get();
        return (int)($result[0]['count'] ?? 0);
    }

    public function findRoleAssignments(array $filters = []): array
    {
        $query = $this->db->select($this->table, $this->defaultFields);

        if (isset($filters['role_uuid'])) {
            $query->where(['role_uuid' => $filters['role_uuid']]);
        }

        if (isset($filters['granted_by'])) {
            $query->where(['granted_by' => $filters['granted_by']]);
        }

        if (isset($filters['active_only']) && $filters['active_only']) {
            $query->whereRaw("(expires_at IS NULL OR expires_at >= ?)", [date('Y-m-d H:i:s')]);
        }

        $query->orderBy(['created_at' => 'DESC']);

        $results = $query->get();
        return array_map(fn($row) => new UserRole($row), $results);
    }

    /**
     * Get paginated user role history
     *
     * @param string $userUuid User UUID to get history for
     * @param array $filters Additional filters
     * @param int $page Page number (1-based)
     * @param int $perPage Number of items per page
     * @return array Paginated results with metadata
     */
    public function getUserRoleHistoryPaginated(
        string $userUuid,
        array $filters = [],
        int $page = 1,
        int $perPage = 25
    ): array {
        // Start with base query
        $query = $this->db->select($this->table, $this->defaultFields)
            ->where(['user_uuid' => $userUuid]);

        // Apply filters
        if (isset($filters['exclude_deleted']) && $filters['exclude_deleted']) {
            $query->whereNull('deleted_at');
        }

        if (isset($filters['role_uuid'])) {
            $query->where(['role_uuid' => $filters['role_uuid']]);
        }

        if (isset($filters['granted_by'])) {
            $query->where(['granted_by' => $filters['granted_by']]);
        }

        // Store active_only filter for post-processing
        $filterActiveOnly = isset($filters['active_only']) && $filters['active_only'];

        // Order by most recent first
        $query->orderBy(['created_at' => 'DESC']);

        // Use QueryBuilder's pagination method
        $result = $query->paginate($page, $perPage);

        // Transform data to include role information
        $transformedData = [];
        $currentTime = date('Y-m-d H:i:s');
        foreach ($result['data'] as $row) {
            $userRole = new UserRole($row);

            // Apply active_only filter if needed
            if ($filterActiveOnly) {
                $expiresAt = $userRole->getExpiresAt();
                if ($expiresAt !== null && $expiresAt < $currentTime) {
                    continue; // Skip expired roles
                }
            }

            // Get role details separately to avoid complex joins
            $roleData = $this->db->select('roles', ['name', 'slug', 'description'])
                ->where(['uuid' => $row['role_uuid']])
                ->first();

            $transformedData[] = [
                'uuid' => $userRole->getUuid(),
                'user_uuid' => $userRole->getUserUuid(),
                'role_uuid' => $userRole->getRoleUuid(),
                'scope' => $userRole->getScope(),
                'granted_by' => $userRole->getGrantedBy(),
                'expires_at' => $userRole->getExpiresAt(),
                'created_at' => $userRole->getCreatedAt(),
                'deleted_at' => $row['deleted_at'] ?? null,
                'role' => $roleData ? [
                    'name' => $roleData['name'],
                    'slug' => $roleData['slug'],
                    'description' => $roleData['description']
                ] : null
            ];
        }

        return [
            'data' => $transformedData,
            'pagination' => $result['pagination']
        ];
    }
}
