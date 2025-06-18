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
        return $this->findUserRoleByUuid($uuid);
    }

    public function findUserRoleByUuid(string $uuid): ?UserRole
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
            $currentTime = $this->db->getDriver()->formatDateTime();
            $query->whereGreaterThanOrEqual('expires_at', $currentTime)->orWhereNull('expires_at');
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
        $currentTime = $this->db->getDriver()->formatDateTime();
        $query->whereGreaterThanOrEqual('expires_at', $currentTime)->orWhereNull('expires_at');

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
        $currentTime = $this->db->getDriver()->formatDateTime();
        $query->whereGreaterThanOrEqual('expires_at', $currentTime)->orWhereNull('expires_at');

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
        $currentTime = $this->db->getDriver()->formatDateTime();
        $results = $this->db->select($this->table, $this->defaultFields)
            ->whereNotNull('expires_at')
            ->whereLessThan('expires_at', $currentTime)
            ->orderBy(['expires_at' => 'ASC'])
            ->get();

        return array_map(fn($row) => new UserRole($row), $results);
    }

    public function cleanupExpiredRoles(): int
    {
        $currentTime = $this->db->getDriver()->formatDateTime();

        $expiredCount = $this->db->select($this->table, ['COUNT(*) as count'])
            ->whereNotNull('expires_at')
            ->whereLessThan('expires_at', $currentTime)
            ->get();

        $count = (int)($expiredCount[0]['count'] ?? 0);

        if ($count > 0) {
            // Get expired role UUIDs and delete them individually
            $deleteQuery = $this->db->select($this->table, ['uuid'])
                ->whereNotNull('expires_at')
                ->whereLessThan('expires_at', $currentTime);

            $expiredUuids = array_column($deleteQuery->get(), 'uuid');
            if (!empty($expiredUuids)) {
                $this->bulkDelete($expiredUuids);
            }
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
            $currentTime = $this->db->getDriver()->formatDateTime();
            $query->whereGreaterThanOrEqual('expires_at', $currentTime)->orWhereNull('expires_at');
        }

        $results = $query->get();
        return array_column($results, 'user_uuid');
    }

    public function countUserRoles(string $userUuid, array $filters = []): int
    {
        $query = $this->db->select($this->table, ['COUNT(*) as count'])
            ->where(['user_uuid' => $userUuid]);

        if (isset($filters['active_only']) && $filters['active_only']) {
            $currentTime = $this->db->getDriver()->formatDateTime();
            $query->whereGreaterThanOrEqual('expires_at', $currentTime)->orWhereNull('expires_at');
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
            $currentTime = $this->db->getDriver()->formatDateTime();
            $query->whereGreaterThanOrEqual('expires_at', $currentTime)->orWhereNull('expires_at');
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
        $currentTime = $this->db->getDriver()->formatDateTime();

        // First pass: collect valid user roles and their role UUIDs
        $validUserRoles = [];
        $roleUuids = [];
        foreach ($result['data'] as $row) {
            $userRole = new UserRole($row);

            // Apply active_only filter if needed
            if ($filterActiveOnly) {
                $expiresAt = $userRole->getExpiresAt();
                if ($expiresAt !== null && $expiresAt < $currentTime) {
                    continue; // Skip expired roles
                }
            }

            $validUserRoles[] = ['row' => $row, 'userRole' => $userRole];
            $roleUuids[] = $row['role_uuid'];
        }

        // Fetch all role data in a single query to avoid N+1 problem
        $rolesMap = [];
        if (!empty($roleUuids)) {
            $roleUuids = array_unique($roleUuids); // Remove duplicates
            $roles = $this->db->select('roles', ['uuid', 'name', 'slug', 'description'])
                ->whereIn('uuid', $roleUuids)
                ->get();

            // Create a map for quick lookup
            foreach ($roles as $role) {
                $rolesMap[$role['uuid']] = $role;
            }
        }

        // Second pass: build final transformed data with role information
        foreach ($validUserRoles as $item) {
            $row = $item['row'];
            $userRole = $item['userRole'];
            $roleData = $rolesMap[$row['role_uuid']] ?? null;

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

    /**
     * Get user roles for multiple users efficiently
     *
     * @param array $userUuids Array of user UUIDs
     * @param array $scope Optional scope filter
     * @return array Array of UserRole objects
     */
    public function getBulkUserRoles(array $userUuids, array $scope = []): array
    {
        if (empty($userUuids)) {
            return [];
        }

        $query = $this->db->select($this->table, $this->defaultFields)
            ->whereIn('user_uuid', $userUuids);

        // Apply scope filters if provided
        if (!empty($scope)) {
            $query->where(['scope' => $scope]);
        }

        $results = $query->get();
        $userRoles = [];

        foreach ($results as $row) {
            $userRoles[] = new UserRole($row);
        }

        return $userRoles;
    }
}
