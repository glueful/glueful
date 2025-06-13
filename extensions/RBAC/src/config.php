<?php

/**
 * RBAC Extension Configuration
 *
 * Configuration settings for the Role-Based Access Control extension
 */

declare(strict_types=1);

return [
    /**
     * Extension Information
     */
    'extension' => [
        'name' => 'RBAC',
        'version' => '1.0.0',
        'description' => 'Role-Based Access Control system with hierarchical roles and permissions',
        'author' => 'Glueful Framework',
        'namespace' => 'Glueful\\Extensions\\RBAC',
    ],

    /**
     * Database Configuration
     */
    'database' => [
        'table_prefix' => '',
        'tables' => [
            'roles' => 'roles',
            'permissions' => 'permissions',
            'user_roles' => 'user_roles',
            'user_permissions' => 'user_permissions',
            'role_permissions' => 'role_permissions'
        ]
    ],

    /**
     * Permission System Configuration
     */
    'permissions' => [
        'provider_name' => 'rbac',
        'cache_enabled' => true,
        'cache_ttl' => 3600, // 1 hour
        'cache_prefix' => 'rbac_',
        'inheritance_enabled' => true,
        'temporal_permissions' => true,
        'resource_filtering' => true,
        'scoped_permissions' => true
    ],

    /**
     * Role Hierarchy Configuration
     */
    'roles' => [
        'max_hierarchy_depth' => 10,
        'inherit_permissions' => true,
        'allow_circular_references' => false,
        'system_roles_protected' => true,
        'default_role_status' => 'active'
    ],

    /**
     * Security Configuration
     */
    'security' => [
        'require_authentication' => true,
        'admin_only_management' => false,
        'audit_trail' => true,
        'permission_inheritance_check' => true,
        'validate_permission_context' => true
    ],

    /**
     * Performance Configuration
     */
    'performance' => [
        'batch_size' => 100,
        'enable_query_optimization' => true,
        'lazy_load_permissions' => true,
        'memory_cache_enabled' => true,
        'memory_cache_size' => 1000 // Number of cached permission checks
    ],

    /**
     * Default System Permissions
     * These permissions are created during extension installation
     */
    'default_permissions' => [
        // RBAC Management Permissions
        [
            'name' => 'View RBAC Roles',
            'slug' => 'rbac.roles.view',
            'description' => 'View roles and role information',
            'category' => 'rbac',
            'resource_type' => 'role',
            'is_system' => true
        ],
        [
            'name' => 'Create RBAC Roles',
            'slug' => 'rbac.roles.create',
            'description' => 'Create new roles',
            'category' => 'rbac',
            'resource_type' => 'role',
            'is_system' => true
        ],
        [
            'name' => 'Update RBAC Roles',
            'slug' => 'rbac.roles.update',
            'description' => 'Update existing roles',
            'category' => 'rbac',
            'resource_type' => 'role',
            'is_system' => true
        ],
        [
            'name' => 'Delete RBAC Roles',
            'slug' => 'rbac.roles.delete',
            'description' => 'Delete roles',
            'category' => 'rbac',
            'resource_type' => 'role',
            'is_system' => true
        ],
        [
            'name' => 'Assign RBAC Roles',
            'slug' => 'rbac.roles.assign',
            'description' => 'Assign roles to users',
            'category' => 'rbac',
            'resource_type' => 'role',
            'is_system' => true
        ],
        [
            'name' => 'Revoke RBAC Roles',
            'slug' => 'rbac.roles.revoke',
            'description' => 'Revoke roles from users',
            'category' => 'rbac',
            'resource_type' => 'role',
            'is_system' => true
        ],
        [
            'name' => 'Manage RBAC Roles',
            'slug' => 'rbac.roles.manage',
            'description' => 'Full role management access',
            'category' => 'rbac',
            'resource_type' => 'role',
            'is_system' => true
        ],

        // Permission Management
        [
            'name' => 'View RBAC Permissions',
            'slug' => 'rbac.permissions.view',
            'description' => 'View permissions and permission information',
            'category' => 'rbac',
            'resource_type' => 'permission',
            'is_system' => true
        ],
        [
            'name' => 'Create RBAC Permissions',
            'slug' => 'rbac.permissions.create',
            'description' => 'Create new permissions',
            'category' => 'rbac',
            'resource_type' => 'permission',
            'is_system' => true
        ],
        [
            'name' => 'Update RBAC Permissions',
            'slug' => 'rbac.permissions.update',
            'description' => 'Update existing permissions',
            'category' => 'rbac',
            'resource_type' => 'permission',
            'is_system' => true
        ],
        [
            'name' => 'Delete RBAC Permissions',
            'slug' => 'rbac.permissions.delete',
            'description' => 'Delete permissions',
            'category' => 'rbac',
            'resource_type' => 'permission',
            'is_system' => true
        ],
        [
            'name' => 'Assign RBAC Permissions',
            'slug' => 'rbac.permissions.assign',
            'description' => 'Assign permissions to users',
            'category' => 'rbac',
            'resource_type' => 'permission',
            'is_system' => true
        ],
        [
            'name' => 'Revoke RBAC Permissions',
            'slug' => 'rbac.permissions.revoke',
            'description' => 'Revoke permissions from users',
            'category' => 'rbac',
            'resource_type' => 'permission',
            'is_system' => true
        ],
        [
            'name' => 'Manage RBAC Permissions',
            'slug' => 'rbac.permissions.manage',
            'description' => 'Full permission management access',
            'category' => 'rbac',
            'resource_type' => 'permission',
            'is_system' => true
        ],

        // User Management
        [
            'name' => 'View RBAC Users',
            'slug' => 'rbac.users.view',
            'description' => 'View user role and permission assignments',
            'category' => 'rbac',
            'resource_type' => 'user',
            'is_system' => true
        ],
        [
            'name' => 'Manage RBAC Users',
            'slug' => 'rbac.users.manage',
            'description' => 'Manage user role and permission assignments',
            'category' => 'rbac',
            'resource_type' => 'user',
            'is_system' => true
        ],

        // General RBAC Access
        [
            'name' => 'RBAC Check',
            'slug' => 'rbac.check',
            'description' => 'Check permissions and roles',
            'category' => 'rbac',
            'resource_type' => 'general',
            'is_system' => true
        ],
        [
            'name' => 'View RBAC Statistics',
            'slug' => 'rbac.stats.view',
            'description' => 'View RBAC statistics and reports',
            'category' => 'rbac',
            'resource_type' => 'general',
            'is_system' => true
        ],
        [
            'name' => 'RBAC Maintenance',
            'slug' => 'rbac.maintenance',
            'description' => 'Perform RBAC maintenance operations',
            'category' => 'rbac',
            'resource_type' => 'general',
            'is_system' => true
        ]
    ],

    /**
     * Default System Roles
     * These roles are created during extension installation
     */
    'default_roles' => [
        [
            'name' => 'RBAC Administrator',
            'slug' => 'rbac_admin',
            'description' => 'Full access to RBAC system management',
            'level' => 0,
            'status' => 'active',
            'is_system' => true,
            'permissions' => [
                'rbac.roles.view',
                'rbac.roles.create',
                'rbac.roles.update',
                'rbac.roles.delete',
                'rbac.roles.assign',
                'rbac.roles.revoke',
                'rbac.roles.manage',
                'rbac.permissions.view',
                'rbac.permissions.create',
                'rbac.permissions.update',
                'rbac.permissions.delete',
                'rbac.permissions.assign',
                'rbac.permissions.revoke',
                'rbac.permissions.manage',
                'rbac.users.view',
                'rbac.users.manage',
                'rbac.check',
                'rbac.stats.view',
                'rbac.maintenance'
            ]
        ],
        [
            'name' => 'Role Manager',
            'slug' => 'role_manager',
            'description' => 'Manage roles and role assignments',
            'level' => 1,
            'status' => 'active',
            'is_system' => false,
            'permissions' => [
                'rbac.roles.view',
                'rbac.roles.create',
                'rbac.roles.update',
                'rbac.roles.assign',
                'rbac.roles.revoke',
                'rbac.users.view',
                'rbac.check'
            ]
        ],
        [
            'name' => 'Permission Manager',
            'slug' => 'permission_manager',
            'description' => 'Manage permissions and permission assignments',
            'level' => 1,
            'status' => 'active',
            'is_system' => false,
            'permissions' => [
                'rbac.permissions.view',
                'rbac.permissions.assign',
                'rbac.permissions.revoke',
                'rbac.users.view',
                'rbac.check'
            ]
        ]
    ],

    /**
     * Event Configuration
     */
    'events' => [
        'role_created' => true,
        'role_updated' => true,
        'role_deleted' => true,
        'role_assigned' => true,
        'role_revoked' => true,
        'permission_created' => true,
        'permission_updated' => true,
        'permission_deleted' => true,
        'permission_assigned' => true,
        'permission_revoked' => true
    ],

    /**
     * API Configuration
     */
    'api' => [
        'base_path' => '/rbac',
        'version' => 'v1',
        'rate_limiting' => true,
        'documentation' => true,
        'require_ssl' => false
    ],

    /**
     * Cleanup Configuration
     */
    'cleanup' => [
        'expired_permissions_enabled' => true,
        'expired_roles_enabled' => true,
        'cleanup_interval' => 'daily',
        'retention_period' => 30 // days
    ],

    /**
     * Validation Rules
     */
    'validation' => [
        'role_name_min_length' => 2,
        'role_name_max_length' => 100,
        'role_slug_pattern' => '/^[a-z0-9_-]+$/',
        'permission_name_min_length' => 2,
        'permission_name_max_length' => 100,
        'permission_slug_pattern' => '/^[a-z0-9._-]+$/',
        'max_role_assignments_per_user' => 50,
        'max_direct_permissions_per_user' => 100
    ],

    /**
     * Logging Configuration
     */
    'logging' => [
        'enabled' => true,
        'level' => 'info',
        'channels' => ['rbac'],
        'log_role_changes' => true,
        'log_permission_changes' => true,
        'log_assignment_changes' => true,
        'log_check_operations' => false // Set to true for debugging
    ]
];
