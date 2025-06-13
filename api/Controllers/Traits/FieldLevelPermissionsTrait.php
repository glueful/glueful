<?php

declare(strict_types=1);

namespace Glueful\Controllers\Traits;

/**
 * FieldLevelPermissionsTrait
 *
 * Provides field-level access control for sensitive data.
 * Filters out sensitive fields based on user permissions.
 *
 * Performance Impact: Medium (20-40% on large datasets)
 * - Iterates through every record and field
 * - Use caching and pagination to minimize impact
 *
 * Usage:
 * ```php
 * class SecureResourceController extends ResourceController
 * {
 *     use FieldLevelPermissionsTrait;
 *
 *     protected bool $enableFieldPermissions = true;
 *
 *     // Override to customize sensitive fields
 *     protected function getSensitiveFields(): array
 *     {
 *         return [
 *             'users' => ['password', 'email', 'phone'],
 *             'orders' => ['payment_info', 'billing_address']
 *         ];
 *     }
 * }
 * ```
 *
 * Permission Format: `resource.{table}.{operation}.{field}`
 * Example: `resource.users.read.email`
 *
 * @package Glueful\Controllers\Traits
 */
trait FieldLevelPermissionsTrait
{
    /**
     * Apply field-level permissions if enabled
     */
    protected function applyFieldPermissions($data, string $table, string $operation)
    {
        if (!$this->enableFieldPermissions) {
            return $data;
        }

        $sensitiveFields = $this->getSensitiveFieldsForTable($table);

        if (empty($sensitiveFields)) {
            return $data; // No field restrictions for this table
        }

        // Handle paginated results (data array with 'data' key)
        if (isset($data['data']) && is_array($data['data'])) {
            $data['data'] = $this->filterFieldsInRecords($data['data'], $table, $operation, $sensitiveFields);
            return $data;
        }

        // Handle single record or array of records
        return $this->filterFieldsInRecords($data, $table, $operation, $sensitiveFields);
    }

    /**
     * Filter sensitive fields from records
     */
    protected function filterFieldsInRecords($data, string $table, string $operation, array $sensitiveFields)
    {
        // Single record
        if (!isset($data[0]) || !is_array($data[0])) {
            return $this->filterSingleRecord($data, $table, $operation, $sensitiveFields);
        }

        // Multiple records
        foreach ($data as $index => $record) {
            $data[$index] = $this->filterSingleRecord($record, $table, $operation, $sensitiveFields);
        }

        return $data;
    }

    /**
     * Filter sensitive fields from a single record
     */
    protected function filterSingleRecord(
        array $record,
        string $table,
        string $operation,
        array $sensitiveFields
    ): array {
        foreach ($sensitiveFields as $field) {
            if (isset($record[$field]) && !$this->can("resource.{$table}.{$operation}.{$field}")) {
                unset($record[$field]); // Remove restricted field
            }
        }

        return $record;
    }

    /**
     * Get sensitive fields for a specific table
     */
    protected function getSensitiveFieldsForTable(string $table): array
    {
        $allSensitiveFields = $this->getSensitiveFields();
        return $allSensitiveFields[$table] ?? [];
    }

    /**
     * Get sensitive fields configuration
     * Override this method to customize sensitive fields
     */
    protected function getSensitiveFields(): array
    {
        // Allow configuration override
        $configFields = config('resource.sensitive_fields', []);

        if (!empty($configFields)) {
            return $configFields;
        }

        // Sensible defaults for common sensitive fields
        return [
            'users' => ['password', 'ip_address', 'x_forwarded_for_ip_address', 'user_agent'],
            'profiles' => ['deleted_at'],
            'auth_sessions' => ['access_token', 'refresh_token', 'token_fingerprint'],
            'app_logs' => ['context'],
            'audit_logs' => ['context', 'raw_data']
        ];
    }

    /**
     * Cache field permissions for better performance
     */
    protected function getUserFieldPermissions(string $table, string $operation): array
    {
        $cacheKey = "field_perms:{$table}:{$operation}:" . ($this->getCurrentUserUuid() ?? 'anonymous');

        return $this->cacheByPermission(
            $cacheKey,
            fn() => $this->calculateFieldPermissions($table, $operation),
            1800 // 30 minutes
        );
    }

    /**
     * Calculate which fields the current user can access
     */
    protected function calculateFieldPermissions(string $table, string $operation): array
    {
        $sensitiveFields = $this->getSensitiveFieldsForTable($table);
        $allowedFields = [];

        foreach ($sensitiveFields as $field) {
            if ($this->can("resource.{$table}.{$operation}.{$field}")) {
                $allowedFields[] = $field;
            }
        }

        return $allowedFields;
    }
}
