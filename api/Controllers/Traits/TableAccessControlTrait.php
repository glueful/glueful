<?php

declare(strict_types=1);

namespace Glueful\Controllers\Traits;

/**
 * TableAccessControlTrait
 *
 * Provides table-level access control for sensitive resources.
 * Restricts access to specific tables based on admin permissions.
 *
 * Performance Impact: Minimal (<5% overhead)
 * - Simple array lookup + single permission check per request
 *
 * Usage:
 * ```php
 * class SecureResourceController extends ResourceController
 * {
 *     use TableAccessControlTrait;
 *
 *     protected bool $enableTableAccessControl = true;
 * }
 * ```
 *
 * Configuration:
 * Set restricted tables in config/resource.php:
 * ```php
 * 'restricted_tables' => [
 *     'app_logs' => 'admin.logs.access',
 *     'auth_sessions' => 'admin.sessions.access'
 * ]
 * ```
 *
 * @package Glueful\Controllers\Traits
 */
trait TableAccessControlTrait
{
    /**
     * Apply table access control if enabled
     */
    protected function applyTableAccessControl(string $table): void
    {
        if (!$this->enableTableAccessControl) {
            return;
        }

        $restrictedTables = $this->getRestrictedTables();

        if (isset($restrictedTables[$table])) {
            $this->requirePermission($restrictedTables[$table]);
        }
    }

    /**
     * Get restricted tables configuration
     */
    protected function getRestrictedTables(): array
    {
        // Allow configuration override
        $configTables = config('resource.restricted_tables', []);

        if (!empty($configTables)) {
            return $configTables;
        }

        // Sensible defaults for common sensitive tables
        return [
            'app_logs' => 'admin.logs.access',
            'auth_sessions' => 'admin.sessions.access',
            'users' => 'users.admin.access',
            'audit_logs' => 'audit.access'
        ];
    }
}
