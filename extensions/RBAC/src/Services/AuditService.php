<?php

declare(strict_types=1);

namespace Glueful\Extensions\RBAC\Services;

use Glueful\Logging\LogManager;
use Psr\Log\LoggerInterface;

/**
 * RBAC Audit Service
 *
 * Handles audit logging for RBAC operations including:
 * - Role changes (create, update, delete, assign, revoke)
 * - Permission changes (create, update, delete, assign, revoke)
 * - Permission checks and validations
 * - Security-related events
 *
 * This service integrates with the framework's audit logging system
 * to provide comprehensive tracking of all RBAC-related activities.
 */
class AuditService
{
    private LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? LogManager::getInstance()->channel('rbac_audit');
    }
    /**
     * Log role created event
     *
     * @param array $roleData Role data that was created
     * @param string|null $createdBy UUID of user who created the role
     * @return void
     */
    public function logRoleCreated(array $roleData, ?string $createdBy = null): void
    {
        $context = [
            'entity_type' => 'role',
            'entity_id' => $roleData['uuid'] ?? null,
            'role_name' => $roleData['name'] ?? null,
            'role_slug' => $roleData['slug'] ?? null,
            'is_system' => $roleData['is_system'] ?? false,
            'created_by' => $createdBy,
            'role_data' => $this->sanitizeRoleData($roleData)
        ];

        $this->logger->info('Role created', array_merge($context, [
            'event_type' => 'role_created',
            'category' => 'rbac_data'
        ]));
    }

    /**
     * Log role updated event
     *
     * @param string $roleUuid Role UUID
     * @param array $oldData Previous role data
     * @param array $newData Updated role data
     * @param string|null $updatedBy UUID of user who updated the role
     * @return void
     */
    public function logRoleUpdated(string $roleUuid, array $oldData, array $newData, ?string $updatedBy = null): void
    {
        $changes = $this->calculateChanges($oldData, $newData);

        $context = [
            'entity_type' => 'role',
            'entity_id' => $roleUuid,
            'role_name' => $newData['name'] ?? $oldData['name'] ?? null,
            'updated_by' => $updatedBy,
            'changes' => $changes,
            'old_data' => $this->sanitizeRoleData($oldData),
            'new_data' => $this->sanitizeRoleData($newData)
        ];

        $this->logger->info('Role updated', array_merge($context, [
            'event_type' => 'role_updated',
            'category' => 'rbac_data'
        ]));
    }

    /**
     * Log role deleted event
     *
     * @param string $roleUuid Role UUID
     * @param array $roleData Role data that was deleted
     * @param string|null $deletedBy UUID of user who deleted the role
     * @return void
     */
    public function logRoleDeleted(string $roleUuid, array $roleData, ?string $deletedBy = null): void
    {
        $context = [
            'entity_type' => 'role',
            'entity_id' => $roleUuid,
            'role_name' => $roleData['name'] ?? null,
            'role_slug' => $roleData['slug'] ?? null,
            'is_system' => $roleData['is_system'] ?? false,
            'deleted_by' => $deletedBy,
            'deleted_data' => $this->sanitizeRoleData($roleData)
        ];

        $this->logger->warning('Role deleted', array_merge($context, [
            'event_type' => 'role_deleted',
            'category' => 'rbac_data'
        ]));
    }

    /**
     * Log role assigned to user event
     *
     * @param string $userUuid User UUID
     * @param string $roleUuid Role UUID
     * @param array $assignmentData Assignment details (scope, expiry, etc.)
     * @param string|null $assignedBy UUID of user who made the assignment
     * @return void
     */
    public function logRoleAssigned(
        string $userUuid,
        string $roleUuid,
        array $assignmentData,
        ?string $assignedBy = null
    ): void {
        $context = [
            'entity_type' => 'role_assignment',
            'entity_id' => $roleUuid,
            'user_uuid' => $userUuid,
            'role_uuid' => $roleUuid,
            'assigned_by' => $assignedBy,
            'scope' => $assignmentData['scope'] ?? null,
            'expires_at' => $assignmentData['expires_at'] ?? null,
            'assignment_data' => $assignmentData
        ];

        $this->logger->info('Role assigned', array_merge($context, [
            'event_type' => 'role_assigned',
            'category' => 'rbac_data'
        ]));
    }

    /**
     * Log role revoked from user event
     *
     * @param string $userUuid User UUID
     * @param string $roleUuid Role UUID
     * @param string|null $revokedBy UUID of user who revoked the role
     * @return void
     */
    public function logRoleRevoked(string $userUuid, string $roleUuid, ?string $revokedBy = null): void
    {
        $context = [
            'entity_type' => 'role_revocation',
            'entity_id' => $roleUuid,
            'user_uuid' => $userUuid,
            'role_uuid' => $roleUuid,
            'revoked_by' => $revokedBy
        ];

        $this->logger->info('Role revoked', array_merge($context, [
            'event_type' => 'role_revoked',
            'category' => 'rbac_data'
        ]));
    }

    /**
     * Log permission created event
     *
     * @param array $permissionData Permission data that was created
     * @param string|null $createdBy UUID of user who created the permission
     * @return void
     */
    public function logPermissionCreated(array $permissionData, ?string $createdBy = null): void
    {
        $context = [
            'entity_type' => 'permission',
            'entity_id' => $permissionData['uuid'] ?? null,
            'permission_name' => $permissionData['name'] ?? null,
            'permission_slug' => $permissionData['slug'] ?? null,
            'category' => $permissionData['category'] ?? null,
            'resource_type' => $permissionData['resource_type'] ?? null,
            'is_system' => $permissionData['is_system'] ?? false,
            'created_by' => $createdBy,
            'permission_data' => $this->sanitizePermissionData($permissionData)
        ];

        $this->logger->info('Permission created', array_merge($context, [
            'event_type' => 'permission_created',
            'category' => 'rbac_data'
        ]));
    }

    /**
     * Log permission updated event
     *
     * @param string $permissionUuid Permission UUID
     * @param array $oldData Previous permission data
     * @param array $newData Updated permission data
     * @param string|null $updatedBy UUID of user who updated the permission
     * @return void
     */
    public function logPermissionUpdated(
        string $permissionUuid,
        array $oldData,
        array $newData,
        ?string $updatedBy = null
    ): void {
        $changes = $this->calculateChanges($oldData, $newData);

        $context = [
            'entity_type' => 'permission',
            'entity_id' => $permissionUuid,
            'permission_name' => $newData['name'] ?? $oldData['name'] ?? null,
            'permission_slug' => $newData['slug'] ?? $oldData['slug'] ?? null,
            'updated_by' => $updatedBy,
            'changes' => $changes,
            'old_data' => $this->sanitizePermissionData($oldData),
            'new_data' => $this->sanitizePermissionData($newData)
        ];

        $this->logger->info('Permission updated', array_merge($context, [
            'event_type' => 'permission_updated',
            'category' => 'rbac_data'
        ]));
    }

    /**
     * Log permission deleted event
     *
     * @param string $permissionUuid Permission UUID
     * @param array $permissionData Permission data that was deleted
     * @param string|null $deletedBy UUID of user who deleted the permission
     * @return void
     */
    public function logPermissionDeleted(string $permissionUuid, array $permissionData, ?string $deletedBy = null): void
    {
        $context = [
            'entity_type' => 'permission',
            'entity_id' => $permissionUuid,
            'permission_name' => $permissionData['name'] ?? null,
            'permission_slug' => $permissionData['slug'] ?? null,
            'category' => $permissionData['category'] ?? null,
            'is_system' => $permissionData['is_system'] ?? false,
            'deleted_by' => $deletedBy,
            'deleted_data' => $this->sanitizePermissionData($permissionData)
        ];

        $this->logger->warning('Permission deleted', array_merge($context, [
            'event_type' => 'permission_deleted',
            'category' => 'rbac_data'
        ]));
    }

    /**
     * Log permission assigned to user event
     *
     * @param string $userUuid User UUID
     * @param string $permissionUuid Permission UUID
     * @param array $assignmentData Assignment details (resource, constraints, expiry, etc.)
     * @param string|null $grantedBy UUID of user who granted the permission
     * @return void
     */
    public function logPermissionAssigned(
        string $userUuid,
        string $permissionUuid,
        array $assignmentData,
        ?string $grantedBy = null
    ): void {
        $context = [
            'entity_type' => 'permission_assignment',
            'entity_id' => $permissionUuid,
            'user_uuid' => $userUuid,
            'permission_uuid' => $permissionUuid,
            'granted_by' => $grantedBy,
            'resource' => $assignmentData['resource'] ?? '*',
            'constraints' => $assignmentData['constraints'] ?? null,
            'expires_at' => $assignmentData['expires_at'] ?? null,
            'assignment_data' => $assignmentData
        ];

        $this->logger->info('Permission assigned', array_merge($context, [
            'event_type' => 'permission_assigned',
            'category' => 'rbac_data'
        ]));
    }

    /**
     * Log permission revoked from user event
     *
     * @param string $userUuid User UUID
     * @param string $permissionUuid Permission UUID
     * @param string|null $revokedBy UUID of user who revoked the permission
     * @return void
     */
    public function logPermissionRevoked(string $userUuid, string $permissionUuid, ?string $revokedBy = null): void
    {
        $context = [
            'entity_type' => 'permission_revocation',
            'entity_id' => $permissionUuid,
            'user_uuid' => $userUuid,
            'permission_uuid' => $permissionUuid,
            'revoked_by' => $revokedBy
        ];

        $this->logger->info('Permission revoked', array_merge($context, [
            'event_type' => 'permission_revoked',
            'category' => 'rbac_data'
        ]));
    }

    /**
     * Log permission check event
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission being checked
     * @param string $resource Resource being accessed
     * @param bool $allowed Whether permission was granted
     * @param array $context Additional context
     * @return void
     */
    public function logPermissionCheck(
        string $userUuid,
        string $permission,
        string $resource,
        bool $allowed,
        array $context = []
    ): void {
        // Only log permission checks if explicitly enabled in config
        // This can be very verbose, so it's typically disabled in production
        $config = config('rbac.logging.log_check_operations', false);
        if (!$config) {
            return;
        }

        $auditContext = [
            'entity_type' => 'permission_check',
            'entity_id' => $permission,
            'user_uuid' => $userUuid,
            'permission' => $permission,
            'resource' => $resource,
            'allowed' => $allowed,
            'check_context' => $context,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ];

        $message = $allowed ? 'Permission check granted' : 'Permission check denied';
        $auditContext['event_type'] = $allowed ? 'permission_check_granted' : 'permission_check_denied';
        $auditContext['category'] = 'rbac_security';

        if ($allowed) {
            $this->logger->info($message, $auditContext);
        } else {
            $this->logger->warning($message, $auditContext);
        }
    }

    /**
     * Log security event
     *
     * @param string $event Event type (e.g., 'unauthorized_access', 'suspicious_activity')
     * @param array $data Event data
     * @param string|null $userUuid User UUID if applicable
     * @return void
     */
    public function logSecurityEvent(string $event, array $data, ?string $userUuid = null): void
    {
        $context = [
            'entity_type' => 'security_event',
            'entity_id' => $event,
            'user_uuid' => $userUuid,
            'event_type' => $event,
            'event_data' => $data,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'timestamp' => date('Y-m-d H:i:s'),
            'session_id' => session_id() ?: null
        ];

        // Add event metadata
        $context['event_type'] = 'rbac_security_event';
        $context['category'] = 'rbac_security';

        // Log based on event type severity
        switch ($event) {
            case 'unauthorized_access':
            case 'suspicious_activity':
            case 'security_violation':
                $this->logger->error("RBAC Security Event: $event", $context);
                break;
            case 'failed_permission_check':
            case 'access_denied':
                $this->logger->warning("RBAC Security Event: $event", $context);
                break;
            default:
                $this->logger->info("RBAC Security Event: $event", $context);
                break;
        }
    }

    /**
     * Helper method to sanitize role data for logging
     */
    private function sanitizeRoleData(array $roleData): array
    {
        // Remove sensitive fields that shouldn't be logged
        $sensitiveFields = ['password', 'token', 'secret'];

        $sanitized = array_diff_key($roleData, array_flip($sensitiveFields));

        // Decode JSON metadata for better readability
        if (isset($sanitized['metadata']) && is_string($sanitized['metadata'])) {
            $decoded = json_decode($sanitized['metadata'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $sanitized['metadata'] = $decoded;
            }
        }

        return $sanitized;
    }

    /**
     * Helper method to sanitize permission data for logging
     */
    private function sanitizePermissionData(array $permissionData): array
    {
        // Remove sensitive fields that shouldn't be logged
        $sensitiveFields = ['password', 'token', 'secret'];

        $sanitized = array_diff_key($permissionData, array_flip($sensitiveFields));

        // Decode JSON metadata for better readability
        if (isset($sanitized['metadata']) && is_string($sanitized['metadata'])) {
            $decoded = json_decode($sanitized['metadata'], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $sanitized['metadata'] = $decoded;
            }
        }

        return $sanitized;
    }

    /**
     * Calculate changes between old and new data
     */
    private function calculateChanges(array $oldData, array $newData): array
    {
        $changes = [];

        // Find fields that were modified
        foreach ($newData as $field => $value) {
            if (!isset($oldData[$field]) || $oldData[$field] !== $value) {
                $changes[$field] = [
                    'old' => $oldData[$field] ?? null,
                    'new' => $value
                ];
            }
        }

        // Find fields that were removed
        foreach ($oldData as $field => $value) {
            if (!isset($newData[$field])) {
                $changes[$field] = [
                    'old' => $value,
                    'new' => null
                ];
            }
        }

        return $changes;
    }
}
