<?php

namespace Glueful\Extensions\RBAC\Repositories;

use Glueful\Repository\BaseRepository;
use Glueful\Extensions\RBAC\Models\UserPermission;
use Glueful\Helpers\Utils;

/**
 * User Permission Repository
 *
 * Handles direct user-permission assignments
 *
 * Features:
 * - Direct permission grants/revokes
 * - Resource-level filtering
 * - Temporal constraint handling
 * - Permission overrides
 */
class UserPermissionRepository extends BaseRepository
{
    protected string $table = 'user_permissions';
    protected array $defaultFields = [
        'uuid', 'user_uuid', 'permission_uuid', 'resource_filter',
        'constraints', 'granted_by', 'expires_at', 'created_at'
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
            throw new \RuntimeException('Failed to create user permission');
        }

        return $data['uuid'];
    }

    public function createUserPermission(array $data): ?UserPermission
    {
        $uuid = $this->create($data);
        return $this->findUserPermissionByUuid($uuid);
    }

    public function findUserPermissionByUuid(string $uuid): ?UserPermission
    {
        $result = $this->db->select($this->table, $this->defaultFields)
            ->where(['uuid' => $uuid])
            ->limit(1)
            ->get();

        return $result ? new UserPermission($result[0]) : null;
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

        if (isset($filters['permission_uuid'])) {
            $query->where(['permission_uuid' => $filters['permission_uuid']]);
        }

        if (isset($filters['active_only']) && $filters['active_only']) {
            $currentTime = $this->db->getDriver()->formatDateTime();
            $query->whereGreaterThanOrEqual('expires_at', $currentTime)->orWhereNull('expires_at');
        }

        $query->orderBy(['created_at' => 'DESC']);

        $results = $query->get();
        return array_map(fn($row) => new UserPermission($row), $results);
    }

    public function findByPermission(string $permissionUuid): array
    {
        $results = $this->db->select($this->table, $this->defaultFields)
            ->where(['permission_uuid' => $permissionUuid])
            ->orderBy(['created_at' => 'DESC'])
            ->get();

        return array_map(fn($row) => new UserPermission($row), $results);
    }

    public function findUserPermission(string $userUuid, string $permissionUuid): ?UserPermission
    {
        $result = $this->db->select($this->table, $this->defaultFields)
            ->where([
                'user_uuid' => $userUuid,
                'permission_uuid' => $permissionUuid
            ])
            ->limit(1)
            ->get();

        return $result ? new UserPermission($result[0]) : null;
    }

    public function hasUserPermission(string $userUuid, string $permissionUuid, array $resourceContext = []): bool
    {
        $query = $this->db->select($this->table, ['uuid'])
            ->where([
                'user_uuid' => $userUuid,
                'permission_uuid' => $permissionUuid
            ]);

        // Check if permission is still active (not expired)
        $currentTime = $this->db->getDriver()->formatDateTime();
        $query->whereGreaterThanOrEqual('expires_at', $currentTime)->orWhereNull('expires_at');

        $results = $query->get();

        if (empty($results)) {
            return false;
        }

        // If we have resource context, check resource filters
        if (!empty($resourceContext)) {
            foreach ($results as $row) {
                $userPermission = new UserPermission($row);
                if ($userPermission->matchesResource($resourceContext)) {
                    return true;
                }
            }
            return false;
        }

        return true;
    }

    public function getUserPermissions(string $userUuid, array $context = []): array
    {
        $query = $this->db->select($this->table, $this->defaultFields)
            ->where(['user_uuid' => $userUuid]);

        // Only get active (non-expired) permissions
        $currentTime = $this->db->getDriver()->formatDateTime();
        $query->whereGreaterThanOrEqual('expires_at', $currentTime)->orWhereNull('expires_at');

        $results = $query->get();
        $userPermissions = array_map(fn($row) => new UserPermission($row), $results);

        // Filter by context if provided
        if (!empty($context)) {
            $userPermissions = array_filter($userPermissions, function ($permission) use ($context) {
                return $permission->satisfiesConstraints($context);
            });
        }

        return array_values($userPermissions);
    }

    public function revokeUserPermission(string $userUuid, string $permissionUuid): bool
    {
        return $this->db->delete($this->table, [
            'user_uuid' => $userUuid,
            'permission_uuid' => $permissionUuid
        ]);
    }

    public function revokeAllUserPermissions(string $userUuid): bool
    {
        return $this->db->delete($this->table, ['user_uuid' => $userUuid]);
    }

    public function findExpiredPermissions(): array
    {
        $currentTime = $this->db->getDriver()->formatDateTime();
        $results = $this->db->select($this->table, $this->defaultFields)
            ->whereLessThan('expires_at', $currentTime)
            ->orderBy(['expires_at' => 'ASC'])
            ->get();

        return array_map(fn($row) => new UserPermission($row), $results);
    }

    public function cleanupExpiredPermissions(): int
    {
        $currentTime = $this->db->getDriver()->formatDateTime();

        $expiredCount = $this->db->select($this->table, ['COUNT(*) as count'])
            ->whereNotNull('expires_at')
            ->whereLessThan('expires_at', $currentTime)
            ->get();

        $count = (int)($expiredCount[0]['count'] ?? 0);

        if ($count > 0) {
            // Create a temporary query builder for delete
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

        return array_map(fn($row) => new UserPermission($row), $results);
    }

    public function countUserPermissions(string $userUuid, array $filters = []): int
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
}
