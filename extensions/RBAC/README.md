# RBAC Extension for Glueful

## Overview

The Role-Based Access Control (RBAC) extension provides a comprehensive, modern permission management system for your Glueful application. It implements a hierarchical role-based access control system with direct user permissions, resource-level filtering, and comprehensive audit trails.

## Features

- **Hierarchical Role System**: Create nested roles with inheritance relationships
- **Direct User Permissions**: Assign permissions directly to users that override role permissions
- **Resource-Level Permissions**: Control access to specific resources or resource types
- **Temporal Permissions**: Set expiry dates for both role assignments and direct permissions
- **Permission Inheritance**: Child roles automatically inherit parent role permissions
- **Scoped Permissions**: Support for multi-tenant environments with scoped access control
- **Comprehensive Audit Trails**: Track all RBAC operations with detailed logging
- **Multi-Layer Caching**: Optimized performance with memory and distributed caching
- **RESTful API**: Complete API for managing roles, permissions, and assignments
- **Flexible Configuration**: Extensive configuration options for different use cases

## Installation

### Automatic Installation (Recommended)

**Option 1: Using the Admin Extension (GUI)**

If you have the Admin extension installed and enabled, you can install and enable the RBAC extension through the web interface:

1. Navigate to your admin dashboard (typically `/admin`)
2. Go to the Extensions page
3. Find the RBAC extension in the available extensions list
4. Click "Install" and after "Enable" to activate it

This provides a user-friendly interface for extension management.

**Option 2: Using the CLI**

**Installing from external source:**
```bash
php glueful extensions install <source-url-or-file> RBAC
```

For example:
```bash
php glueful extensions install https://github.com/glueful/extensions/releases/download/v1.0.0/RBAC-1.0.0.gluex RBAC
```

**Enabling local extension:**
```bash
php glueful extensions enable RBAC
```

Both methods automatically handle:
- Adding the extension to `extensions/extensions.json`
- Running database migrations
- Generating API documentation
- Registering the RBAC permission provider
- Setting up audit logging

### Manual Installation

If you're installing manually (copying files directly), you'll need to do these steps after placing the extension in the `extensions/RBAC` directory:

1. Manually add the extension to `extensions/extensions.json`:
```json
{
  "extensions": {
    "RBAC": {
      "version": "1.0.0",
      "enabled": true,
      "installPath": "extensions/RBAC",
      "type": "optional",
      "description": "Role-Based Access Control system",
      "author": "Glueful Team"
    }
  }
}
```

2. Run the migrations to create the necessary database tables:
```bash
php glueful migrate run
```

3. Generate API documentation:
```bash
php glueful generate:json doc
```

4. Restart your web server to apply the changes.

### Verify Installation

Check that the extension is properly enabled:

```bash
php glueful extensions list
php glueful extensions info RBAC
```

## Database Schema

The RBAC extension creates the following tables:

- **`roles`**: Role definitions with hierarchy support
- **`permissions`**: Permission definitions with categories and resource types
- **`user_roles`**: User role assignments with scope and expiry support
- **`user_permissions`**: Direct user permission assignments
- **`role_permissions`**: Role-to-permission mappings

## Configuration

### Environment Variables

Configure RBAC behavior through environment variables or extension config:

```env
# Cache settings
RBAC_CACHE_ENABLED=true
RBAC_CACHE_TTL=3600

# Audit logging
RBAC_AUDIT_ENABLED=true
RBAC_LOG_PERMISSION_CHECKS=false

# Role hierarchy
RBAC_HIERARCHY_ENABLED=true
RBAC_INHERITANCE_ENABLED=true
RBAC_MAX_HIERARCHY_DEPTH=10
```

### Extension Configuration

The extension configuration is automatically loaded from `extensions/RBAC/src/config.php`. You can modify these settings directly in that file or override them through environment variables.

## Usage

### Basic Permission Checking

```php
use Glueful\Permissions\PermissionManager;

$permissionManager = container()->get('permission.manager');

// Check if user has permission
$canEdit = $permissionManager->can($userUuid, 'posts.edit', 'post:123');

// Check with additional context
$canDelete = $permissionManager->can($userUuid, 'posts.delete', 'post:123', [
    'scope' => ['tenant_id' => 'tenant_1'],
    'time_constraint' => '2024-12-31'
]);
```

### Role Management

```php
use Glueful\Extensions\RBAC\Services\RoleService;

$roleService = container()->get('rbac.role_service');

// Create a new role
$adminRole = $roleService->createRole([
    'name' => 'Administrator',
    'slug' => 'admin',
    'description' => 'System administrator with full access'
]);

// Create a child role
$moderatorRole = $roleService->createRole([
    'name' => 'Moderator',
    'slug' => 'moderator',
    'parent_uuid' => $adminRole->getUuid(),
    'description' => 'Content moderator'
]);

// Assign role to user
$roleService->assignRoleToUser($userUuid, $adminRole->getUuid(), [
    'expires_at' => '2024-12-31 23:59:59',
    'scope' => ['tenant_id' => 'tenant_1']
]);
```

### Permission Management

```php
use Glueful\Extensions\RBAC\Services\PermissionAssignmentService;

$permissionService = container()->get('rbac.permission_service');

// Create a new permission
$permission = $permissionService->createPermission([
    'name' => 'Edit Posts',
    'slug' => 'posts.edit',
    'description' => 'Ability to edit blog posts',
    'category' => 'content',
    'resource_type' => 'posts'
]);

// Assign permission directly to user (overrides role permissions)
$permissionService->assignPermissionToUser(
    $userUuid,
    'posts.edit',
    'post:123', // Specific resource
    [
        'expires_at' => '2024-06-30 23:59:59',
        'constraints' => ['ip_range' => '192.168.1.0/24']
    ]
);

// Batch assign permissions
$permissionService->batchAssignPermissions($userUuid, [
    ['permission' => 'posts.read', 'resource' => '*'],
    ['permission' => 'posts.edit', 'resource' => 'post:*'],
    ['permission' => 'comments.moderate', 'resource' => '*']
]);
```

### Using the RBAC Permission Provider

The RBAC extension registers itself as a permission provider:

```php
use Glueful\Extensions\RBAC\RBACPermissionProvider;

$rbacProvider = container()->get('rbac.permission_provider');

// Assign a role
$rbacProvider->assignRole($userUuid, 'editor', [
    'scope' => ['tenant_id' => 'tenant_1'],
    'expires_at' => '2024-12-31 23:59:59'
]);

// Check if user has role
$hasRole = $rbacProvider->hasRole($userUuid, 'admin');

// Get user's effective permissions
$permissions = $rbacProvider->getUserPermissions($userUuid);
```

## API Endpoints

The extension provides a comprehensive REST API:

### Roles
- `GET /rbac/roles` - List all roles
- `POST /rbac/roles` - Create a new role
- `GET /rbac/roles/{uuid}` - Get role details
- `PUT /rbac/roles/{uuid}` - Update role
- `DELETE /rbac/roles/{uuid}` - Delete role

### Permissions
- `GET /rbac/permissions` - List all permissions
- `POST /rbac/permissions` - Create a new permission
- `GET /rbac/permissions/{uuid}` - Get permission details
- `PUT /rbac/permissions/{uuid}` - Update permission
- `DELETE /rbac/permissions/{uuid}` - Delete permission

### User Role Assignments
- `GET /rbac/users/{uuid}/roles` - Get user's roles
- `POST /rbac/users/{uuid}/roles` - Assign role to user
- `DELETE /rbac/users/{uuid}/roles/{roleUuid}` - Remove role from user

### User Permission Assignments
- `GET /rbac/users/{uuid}/permissions` - Get user's direct permissions
- `POST /rbac/users/{uuid}/permissions` - Assign permission to user
- `DELETE /rbac/users/{uuid}/permissions/{permissionUuid}` - Remove permission from user

## Hierarchical Roles

The RBAC system supports role hierarchy:

```php
// Create role hierarchy: Admin -> Manager -> Employee
$adminRole = $roleService->createRole([
    'name' => 'Administrator',
    'slug' => 'admin',
    'level' => 0
]);

$managerRole = $roleService->createRole([
    'name' => 'Manager',
    'slug' => 'manager',
    'parent_uuid' => $adminRole->getUuid(),
    'level' => 1
]);

$employeeRole = $roleService->createRole([
    'name' => 'Employee',
    'slug' => 'employee',
    'parent_uuid' => $managerRole->getUuid(),
    'level' => 2
]);

// Users with admin role automatically inherit manager and employee permissions
```

## Scoped Permissions

Support multi-tenant environments with scoped permissions:

```php
// Assign role with scope
$roleService->assignRoleToUser($userUuid, $managerRole->getUuid(), [
    'scope' => [
        'tenant_id' => 'tenant_1',
        'department' => 'marketing'
    ]
]);

// Check permission with scope context
$canAccess = $permissionManager->can($userUuid, 'reports.view', '*', [
    'scope' => ['tenant_id' => 'tenant_1']
]);
```

## Audit Logging

All RBAC operations are automatically logged:

```php
use Glueful\Extensions\RBAC\Services\AuditService;

$auditService = container()->get('rbac.audit_service');

// Audit logs are automatically created for:
// - Role creation, updates, deletion
// - Permission creation, updates, deletion
// - Role assignments and revocations
// - Permission grants and revocations
// - Permission checks (if enabled)
// - Security events
```

## Caching

The RBAC system uses multi-layer caching:

- **Memory Cache**: In-process caching for the current request
- **Distributed Cache**: Redis/Memcached for cross-request caching
- **Cache Invalidation**: Automatic cache invalidation on permission changes

```php
// Clear user's permission cache
$rbacProvider->invalidateUserCache($userUuid);

// Clear all RBAC cache
$rbacProvider->invalidateAllCache();
```

## Performance Considerations

- Permission checks are cached for 15 minutes by default
- User permissions are cached for 1 hour by default
- Role hierarchy is cached to minimize database queries
- Use resource-specific permissions when possible to improve cache effectiveness

## Security Considerations

- System roles and permissions are protected from modification
- Circular role hierarchies are prevented
- All operations require appropriate permissions
- Audit trails provide accountability
- Cache keys include security context to prevent unauthorized access

## Migration from Legacy Systems

If migrating from an existing permission system:

1. Export existing roles and permissions
2. Use the batch assignment APIs to recreate the structure
3. Test thoroughly with your existing codebase
4. Update permission checks to use the new RBAC provider

## Troubleshooting

### Common Issues

1. **Permissions not working**: Check that the RBAC provider is registered and the extension is enabled
2. **Cache issues**: Try clearing the RBAC cache with `invalidateAllCache()`
3. **Performance issues**: Enable caching and consider using resource-specific permissions
4. **Audit logs not appearing**: Ensure audit logging is enabled in the configuration

### Debug Mode

Enable detailed logging for troubleshooting by setting environment variables:

```env
RBAC_LOG_PERMISSION_CHECKS=true
RBAC_CACHE_ENABLED=false
```

Or modify the configuration in `extensions/RBAC/src/config.php`.

## Requirements

- PHP 8.2 or higher
- Glueful Framework 0.27.0 or higher
- MySQL, PostgreSQL, or SQLite database
- Redis or Memcached (optional, for distributed caching)

## License

This extension is licensed under the same license as the Glueful framework.

## Support

For issues, feature requests, or questions, please create an issue in the repository.