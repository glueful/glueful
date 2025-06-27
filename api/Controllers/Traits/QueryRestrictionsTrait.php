<?php

declare(strict_types=1);

namespace Glueful\Controllers\Traits;

/**
 * QueryRestrictionsTrait
 *
 * Provides query parameter access control for sensitive searches.
 * Restricts which query parameters users can use based on permissions.
 *
 * Performance Impact: Low (5-15% overhead)
 * - Single permission check per restricted parameter
 * - Only runs once per request
 *
 * Usage:
 * ```php
 * class SecureResourceController extends ResourceController
 * {
 *     use QueryRestrictionsTrait;
 *
 *     protected bool $enableQueryRestrictions = true;
 *
 *     // Override to customize restricted parameters
 *     protected function getRestrictedQueryParams(): array
 *     {
 *         return [
 *             'users' => [
 *                 'email' => 'admin.users.search_by_email',
 *                 'ip_address' => 'admin.users.search_by_ip'
 *             ]
 *         ];
 *     }
 * }
 * ```
 *
 * Permission Format: `admin.{table}.search_by_{param}`
 * Example: `admin.users.search_by_email`
 *
 * @package Glueful\Controllers\Traits
 */
trait QueryRestrictionsTrait
{
    /**
     * Apply query parameter restrictions if enabled
     */
    protected function applyQueryRestrictions(array $queryParams, string $table): array
    {
        if (!$this->enableQueryRestrictions) {
            return $queryParams;
        }

        $restrictedParams = $this->getRestrictedParamsForTable($table);

        if (empty($restrictedParams)) {
            return $queryParams; // No restrictions for this table
        }

        foreach ($restrictedParams as $param => $requiredPermission) {
            if (isset($queryParams[$param]) && !$this->can($requiredPermission)) {
                // Remove restricted parameter instead of throwing exception
                // This allows graceful degradation of search functionality
                unset($queryParams[$param]);

                // Optionally log the restriction for audit purposes
                $this->logQueryRestriction($table, $param, $requiredPermission);
            }
        }

        return $queryParams;
    }

    /**
     * Get restricted parameters for a specific table
     */
    protected function getRestrictedParamsForTable(string $table): array
    {
        $allRestrictedParams = $this->getRestrictedQueryParams();
        return $allRestrictedParams[$table] ?? [];
    }

    /**
     * Get restricted query parameters configuration
     * Override this method to customize restricted parameters
     */
    protected function getRestrictedQueryParams(): array
    {
        // Allow configuration override
        $configParams = config('resource.restricted_query_params', []);

        if (!empty($configParams)) {
            return $configParams;
        }

        // Sensible defaults for common sensitive search parameters
        return [
            'users' => [
                'ip_address' => 'admin.users.search_by_ip',
                'deleted_at' => 'admin.users.view_deleted',
                'last_login_date' => 'admin.users.view_activity',
                'user_agent' => 'admin.users.search_by_agent'
            ],
            'auth_sessions' => [
                'ip_address' => 'admin.sessions.search_by_ip',
                'user_agent' => 'admin.sessions.search_by_agent',
                'token_fingerprint' => 'admin.sessions.view_tokens'
            ],
            'app_logs' => [
                'context' => 'admin.logs.search_context',
                'exec_time' => 'admin.logs.performance_data',
                'channel' => 'admin.logs.filter_by_channel'
            ],
            'audit_logs' => [
                'user_uuid' => 'admin.audit.search_by_user',
                'ip_address' => 'admin.audit.search_by_ip',
                'context' => 'admin.audit.view_context'
            ]
        ];
    }

    /**
     * Log query parameter restriction for audit purposes
     */
    protected function logQueryRestriction(string $table, string $param, string $requiredPermission): void
    {
        // Log to error log instead of audit system
        error_log(
            "Query restriction: User blocked from searching {$table} by {$param} (requires {$requiredPermission})"
        );
    }

    /**
     * Validate query parameters more strictly (throws exceptions)
     * Alternative to graceful degradation - use when strict validation is needed
     */
    protected function validateQueryParametersStrict(array $queryParams, string $table): array
    {
        if (!$this->enableQueryRestrictions) {
            return $queryParams;
        }

        $restrictedParams = $this->getRestrictedParamsForTable($table);

        foreach ($restrictedParams as $param => $requiredPermission) {
            if (isset($queryParams[$param]) && !$this->can($requiredPermission)) {
                throw new \Glueful\Permissions\Exceptions\UnauthorizedException(
                    "Access denied: Cannot search by '{$param}'. Required permission: {$requiredPermission}",
                    '403',
                    ''
                );
            }
        }

        return $queryParams;
    }
}
