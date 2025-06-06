<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

/**
 * Permission Audit Interface
 *
 * Contract for permission audit logging implementations.
 * Auditing permission changes and access attempts is crucial
 * for security, compliance, and debugging.
 *
 * This interface supports:
 * - Permission grant/revoke auditing
 * - Access attempt logging
 * - Administrative action tracking
 * - Compliance reporting
 *
 * @package Glueful\Interfaces\Permission
 */
interface PermissionAuditInterface
{
    /**
     * Initialize the audit system
     *
     * Set up audit logging configurations, database connections,
     * and any necessary resources for audit operations.
     *
     * @param array $config Audit configuration
     * @return void
     * @throws \Exception If audit initialization fails
     */
    public function initialize(array $config = []): void;

    /**
     * Log permission granted event
     *
     * Record when a permission is granted to a user.
     *
     * @param string $userUuid User who received the permission
     * @param string $permission Permission that was granted
     * @param string $resource Resource the permission applies to
     * @param string $grantedBy UUID of admin who granted permission
     * @param array $context Additional context (IP, reason, etc.)
     * @return bool True if logged successfully
     */
    public function logPermissionGranted(string $userUuid, string $permission, string $resource, string $grantedBy, array $context = []): bool;

    /**
     * Log permission revoked event
     *
     * Record when a permission is revoked from a user.
     *
     * @param string $userUuid User who lost the permission
     * @param string $permission Permission that was revoked
     * @param string $resource Resource the permission applied to
     * @param string $revokedBy UUID of admin who revoked permission
     * @param array $context Additional context (IP, reason, etc.)
     * @return bool True if logged successfully
     */
    public function logPermissionRevoked(string $userUuid, string $permission, string $resource, string $revokedBy, array $context = []): bool;

    /**
     * Log access attempt
     *
     * Record when a user attempts to access a resource.
     * Logs both successful and failed access attempts.
     *
     * @param string $userUuid User who attempted access
     * @param string $permission Permission required for access
     * @param string $resource Resource being accessed
     * @param bool $granted Whether access was granted
     * @param array $context Additional context (IP, user agent, etc.)
     * @return bool True if logged successfully
     */
    public function logAccessAttempt(string $userUuid, string $permission, string $resource, bool $granted, array $context = []): bool;

    /**
     * Log role assignment
     *
     * Record when a role is assigned to a user.
     *
     * @param string $userUuid User who received the role
     * @param string $role Role that was assigned
     * @param string $assignedBy UUID of admin who assigned role
     * @param array $context Additional context
     * @return bool True if logged successfully
     */
    public function logRoleAssigned(string $userUuid, string $role, string $assignedBy, array $context = []): bool;

    /**
     * Log role removal
     *
     * Record when a role is removed from a user.
     *
     * @param string $userUuid User who lost the role
     * @param string $role Role that was removed
     * @param string $removedBy UUID of admin who removed role
     * @param array $context Additional context
     * @return bool True if logged successfully
     */
    public function logRoleRemoved(string $userUuid, string $role, string $removedBy, array $context = []): bool;

    /**
     * Log administrative action
     *
     * Record administrative actions related to permission management.
     *
     * @param string $action Action performed (e.g., 'bulk_grant', 'cache_clear')
     * @param string $performedBy UUID of admin who performed action
     * @param array $details Action details and affected resources
     * @param array $context Additional context
     * @return bool True if logged successfully
     */
    public function logAdminAction(string $action, string $performedBy, array $details = [], array $context = []): bool;

    /**
     * Get audit trail for a user
     *
     * Retrieve audit logs related to a specific user.
     *
     * @param string $userUuid User UUID to get audit trail for
     * @param array $filters Optional filters (date range, action type, etc.)
     * @param int $limit Maximum number of records to return
     * @param int $offset Offset for pagination
     * @return array Audit trail records
     */
    public function getUserAuditTrail(string $userUuid, array $filters = [], int $limit = 100, int $offset = 0): array;

    /**
     * Get audit trail for a resource
     *
     * Retrieve audit logs related to a specific resource.
     *
     * @param string $resource Resource to get audit trail for
     * @param array $filters Optional filters
     * @param int $limit Maximum number of records to return
     * @param int $offset Offset for pagination
     * @return array Audit trail records
     */
    public function getResourceAuditTrail(string $resource, array $filters = [], int $limit = 100, int $offset = 0): array;

    /**
     * Get audit trail for time period
     *
     * Retrieve audit logs for a specific time period.
     *
     * @param \DateTime $startDate Start of audit period
     * @param \DateTime $endDate End of audit period
     * @param array $filters Optional filters
     * @param int $limit Maximum number of records to return
     * @param int $offset Offset for pagination
     * @return array Audit trail records
     */
    public function getAuditTrailByPeriod(\DateTime $startDate, \DateTime $endDate, array $filters = [], int $limit = 100, int $offset = 0): array;

    /**
     * Search audit logs
     *
     * Search audit logs using various criteria.
     *
     * @param array $criteria Search criteria
     * @param int $limit Maximum number of records to return
     * @param int $offset Offset for pagination
     * @return array Matching audit records
     */
    public function searchAuditLogs(array $criteria, int $limit = 100, int $offset = 0): array;

    /**
     * Generate compliance report
     *
     * Generate a compliance report for auditing purposes.
     *
     * @param string $reportType Type of report (e.g., 'access_summary', 'permission_changes')
     * @param array $parameters Report parameters
     * @return array Report data
     */
    public function generateComplianceReport(string $reportType, array $parameters = []): array;

    /**
     * Archive old audit logs
     *
     * Archive or delete old audit logs based on retention policy.
     *
     * @param \DateTime $cutoffDate Date before which logs should be archived
     * @param bool $delete Whether to delete instead of archive
     * @return int Number of records archived/deleted
     */
    public function archiveOldLogs(\DateTime $cutoffDate, bool $delete = false): int;

    /**
     * Get audit statistics
     *
     * Return statistics about audit logging activity.
     *
     * @param array $filters Optional filters for statistics
     * @return array Audit statistics
     */
    public function getAuditStats(array $filters = []): array;

    /**
     * Configure audit settings
     *
     * Update audit logging configuration.
     *
     * @param array $config New audit configuration
     * @return bool True if configuration updated successfully
     */
    public function configureAudit(array $config): bool;

    /**
     * Check audit system health
     *
     * Perform health check of audit logging system.
     *
     * @return array Health check results
     */
    public function healthCheck(): array;

    /**
     * Enable or disable audit logging
     *
     * Control whether audit events are logged.
     *
     * @param bool $enabled Whether to enable audit logging
     * @return void
     */
    public function setEnabled(bool $enabled): void;

    /**
     * Check if audit logging is enabled
     *
     * @return bool True if audit logging is enabled
     */
    public function isEnabled(): bool;
}
