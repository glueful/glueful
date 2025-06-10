<?php

/**
 * Resource Controller Configuration
 *
 * Configure security features, limits, and restrictions for ResourceController.
 * All features are disabled by default for maximum performance.
 * Enable only the features your application needs.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Security Features
    |--------------------------------------------------------------------------
    |
    | Toggle security features on/off. Each feature adds overhead:
    | - table_access_control: <5% overhead, minimal security
    | - field_permissions: 20-40% overhead on large datasets
    | - bulk_operations: High overhead, use for admin operations only
    | - query_restrictions: 5-15% overhead
    | - ownership_validation: 10-30% overhead on owned resources
    |
    */

    'security' => [
        'table_access_control' => env('RESOURCE_TABLE_ACCESS_CONTROL', false),
        'field_permissions' => env('RESOURCE_FIELD_PERMISSIONS', false),
        'bulk_operations' => env('RESOURCE_BULK_OPERATIONS', false),
        'query_restrictions' => env('RESOURCE_QUERY_RESTRICTIONS', false),
        'ownership_validation' => env('RESOURCE_OWNERSHIP_VALIDATION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Access Control
    |--------------------------------------------------------------------------
    |
    | Define which tables require special permissions to access.
    | Users without the required permission will get a 403 error.
    |
    | Format: 'table_name' => 'required.permission'
    |
    */

    'restricted_tables' => [
        'app_logs' => 'admin.logs.access',
        'auth_sessions' => 'admin.sessions.access',
        'users' => 'users.admin.access',
        'audit_logs' => 'audit.access',
        // Add more restricted tables as needed
    ],

    /*
    |--------------------------------------------------------------------------
    | Field-Level Permissions
    |--------------------------------------------------------------------------
    |
    | Define sensitive fields that require special permissions to view.
    | Fields without permission are automatically removed from responses.
    |
    | Format: 'table_name' => ['field1', 'field2']
    | Permission checked: 'resource.{table}.{operation}.{field}'
    |
    */

    'sensitive_fields' => [
        'users' => [
            'password',
            'ip_address',
            'x_forwarded_for_ip_address',
            'user_agent',
            // Add more sensitive user fields
        ],
        'profiles' => [
            'deleted_at',
            // Add more sensitive profile fields
        ],
        'auth_sessions' => [
            'access_token',
            'refresh_token',
            'token_fingerprint',
        ],
        'app_logs' => [
            'context',
        ],
        'audit_logs' => [
            'context',
            'raw_data',
        ],
        // Add more tables and their sensitive fields
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Parameter Restrictions
    |--------------------------------------------------------------------------
    |
    | Define query parameters that require special permissions to use.
    | Restricted parameters are silently removed if user lacks permission.
    |
    | Format: 'table_name' => ['param' => 'required.permission']
    |
    */

    'restricted_query_params' => [
        'users' => [
            'ip_address' => 'admin.users.search_by_ip',
            'deleted_at' => 'admin.users.view_deleted',
            'last_login_date' => 'admin.users.view_activity',
            'user_agent' => 'admin.users.search_by_agent',
        ],
        'auth_sessions' => [
            'ip_address' => 'admin.sessions.search_by_ip',
            'user_agent' => 'admin.sessions.search_by_agent',
            'token_fingerprint' => 'admin.sessions.view_tokens',
        ],
        'app_logs' => [
            'context' => 'admin.logs.search_context',
            'exec_time' => 'admin.logs.performance_data',
            'channel' => 'admin.logs.filter_by_channel',
        ],
        'audit_logs' => [
            'user_uuid' => 'admin.audit.search_by_user',
            'ip_address' => 'admin.audit.search_by_ip',
            'context' => 'admin.audit.view_context',
        ],
        // Add more tables and their restricted parameters
    ],

    /*
    |--------------------------------------------------------------------------
    | Bulk Operation Limits
    |--------------------------------------------------------------------------
    |
    | Set maximum number of records that can be processed in bulk operations.
    | Lower limits improve security and prevent system overload.
    |
    */

    'bulk_limits' => [
        'delete' => env('RESOURCE_BULK_DELETE_LIMIT', 100),
        'update' => env('RESOURCE_BULK_UPDATE_LIMIT', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limits for different operation types.
    | Format: [requests_per_period, period_in_seconds]
    |
    */

    'rate_limits' => [
        'read' => [100, 60],        // 100 reads per minute
        'create' => [50, 60],       // 50 creates per minute
        'update' => [30, 60],       // 30 updates per minute
        'delete' => [10, 60],       // 10 deletes per minute
        'bulk_delete' => [5, 300],  // 5 bulk deletes per 5 minutes
        'bulk_update' => [3, 300],  // 3 bulk updates per 5 minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configure caching behavior for resource operations.
    | TTL values in seconds.
    |
    */

    'cache' => [
        'list_ttl' => env('RESOURCE_CACHE_LIST_TTL', 600),     // 10 minutes
        'single_ttl' => env('RESOURCE_CACHE_SINGLE_TTL', 300), // 5 minutes
        'field_permissions_ttl' => 1800,                       // 30 minutes
    ],

];
