<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

/**
 * Permission Provider Interface
 *
 * Main contract that all permission extensions must implement.
 * This interface defines the core methods needed for permission checking,
 * assignment, and management within the Glueful framework.
 *
 * Permission providers can implement various models:
 * - Role-Based Access Control (RBAC)
 * - Attribute-Based Access Control (ABAC)
 * - Access Control Lists (ACL)
 * - Custom permission models
 *
 * @package Glueful\Interfaces\Permission
 */
interface PermissionProviderInterface
{
    /**
     * Initialize the permission provider
     *
     * Called when the provider is registered with the system.
     * Use this to set up database connections, load configurations,
     * initialize caches, or perform any other setup tasks.
     *
     * @param array $config Configuration array from extension or system
     * @return void
     * @throws \Exception If initialization fails
     */
    public function initialize(array $config = []): void;

    /**
     * Check if a user has a specific permission for a resource
     *
     * This is the core permission checking method. It should return true
     * if the user has the requested permission, false otherwise.
     *
     * Examples:
     * - can('user123', 'view', 'posts') - Can user view posts?
     * - can('user123', 'edit', 'posts.456') - Can user edit specific post?
     * - can('user123', 'manage', 'users') - Can user manage users?
     *
     * @param string $userUuid User UUID to check permissions for
     * @param string $permission Permission name (e.g., 'view', 'edit', 'delete')
     * @param string $resource Resource identifier (e.g., 'posts', 'users', 'posts.123')
     * @param array $context Additional context (IP, time, location, etc.)
     * @return bool True if user has permission, false otherwise
     */
    public function can(string $userUuid, string $permission, string $resource, array $context = []): bool;

    /**
     * Get all permissions for a user
     *
     * Returns a comprehensive list of all permissions the user has.
     * Format should be consistent across providers for compatibility.
     *
     * Expected format:
     * [
     *     'posts' => ['view', 'create'],
     *     'users' => ['view'],
     *     'admin.settings' => ['view', 'edit']
     * ]
     *
     * @param string $userUuid User UUID to get permissions for
     * @return array Associative array of resource => permissions[]
     */
    public function getUserPermissions(string $userUuid): array;

    /**
     * Assign a permission to a user
     *
     * Grants a specific permission to a user for a resource.
     * This may involve creating role assignments, direct permissions,
     * or other provider-specific mechanisms.
     *
     * @param string $userUuid User UUID to assign permission to
     * @param string $permission Permission name to assign
     * @param string $resource Resource the permission applies to
     * @param array $options Additional options (expiry, constraints, etc.)
     * @return bool True if assignment successful, false otherwise
     */
    public function assignPermission(string $userUuid, string $permission, string $resource, array $options = []): bool;

    /**
     * Revoke a permission from a user
     *
     * Removes a specific permission from a user for a resource.
     * Should handle graceful removal even if permission doesn't exist.
     *
     * @param string $userUuid User UUID to revoke permission from
     * @param string $permission Permission name to revoke
     * @param string $resource Resource the permission applies to
     * @return bool True if revocation successful, false otherwise
     */
    public function revokePermission(string $userUuid, string $permission, string $resource): bool;

    /**
     * Get all available permissions in the system
     *
     * Returns a list of all permission types that can be assigned.
     * Useful for admin interfaces and permission management.
     *
     * Expected format:
     * [
     *     'view' => 'View/Read access',
     *     'create' => 'Create new resources',
     *     'edit' => 'Modify existing resources',
     *     'delete' => 'Remove resources',
     *     'manage' => 'Full administrative access'
     * ]
     *
     * @return array Permission name => description mapping
     */
    public function getAvailablePermissions(): array;

    /**
     * Get all available resources in the system
     *
     * Returns a list of all resources that can have permissions applied.
     * Useful for admin interfaces and permission management.
     *
     * Expected format:
     * [
     *     'users' => 'User Management',
     *     'posts' => 'Blog Posts',
     *     'settings' => 'System Settings'
     * ]
     *
     * @return array Resource name => description mapping
     */
    public function getAvailableResources(): array;

    /**
     * Batch assign multiple permissions
     *
     * Efficiently assign multiple permissions at once.
     * Should use transactions where possible for atomicity.
     *
     * Format:
     * [
     *     ['permission' => 'view', 'resource' => 'posts'],
     *     ['permission' => 'edit', 'resource' => 'posts'],
     *     ['permission' => 'view', 'resource' => 'users']
     * ]
     *
     * @param string $userUuid User UUID to assign permissions to
     * @param array $permissions Array of permission/resource pairs
     * @param array $options Additional options for all assignments
     * @return bool True if all assignments successful, false otherwise
     */
    public function batchAssignPermissions(string $userUuid, array $permissions, array $options = []): bool;

    /**
     * Batch revoke multiple permissions
     *
     * Efficiently revoke multiple permissions at once.
     * Should use transactions where possible for atomicity.
     *
     * @param string $userUuid User UUID to revoke permissions from
     * @param array $permissions Array of permission/resource pairs
     * @return bool True if all revocations successful, false otherwise
     */
    public function batchRevokePermissions(string $userUuid, array $permissions): bool;

    /**
     * Assign a role to a user
     *
     * Assigns a specific role to a user. For role-based permission systems,
     * this is the primary way to grant permissions through role membership.
     * For non-role-based systems, this may translate to equivalent permission grants.
     *
     * @param string $userUuid User UUID to assign role to
     * @param string $roleSlug Role identifier/slug to assign
     * @param array $options Additional options (expiry, scope, constraints, etc.)
     * @return bool True if role assignment successful, false otherwise
     */
    public function assignRole(string $userUuid, string $roleSlug, array $options = []): bool;

    /**
     * Revoke a role from a user
     *
     * Removes a specific role from a user. This will typically revoke
     * all permissions associated with that role.
     *
     * @param string $userUuid User UUID to revoke role from
     * @param string $roleSlug Role identifier/slug to revoke
     * @return bool True if role revocation successful, false otherwise
     */
    public function revokeRole(string $userUuid, string $roleSlug): bool;

    /**
     * Invalidate cached permissions for a user
     *
     * Clear any cached permission data for the specified user.
     * Should trigger cache refresh on next permission check.
     *
     * @param string $userUuid User UUID to invalidate cache for
     * @return void
     */
    public function invalidateUserCache(string $userUuid): void;

    /**
     * Invalidate all cached permissions
     *
     * Clear all cached permission data across the system.
     * Use with caution as this can impact performance.
     *
     * @return void
     */
    public function invalidateAllCache(): void;

    /**
     * Get provider metadata
     *
     * Returns information about this permission provider.
     * Useful for debugging and system information.
     *
     * Expected format:
     * [
     *     'name' => 'Basic RBAC',
     *     'version' => '1.0.0',
     *     'description' => 'Role-based permission system',
     *     'capabilities' => ['roles', 'hierarchies', 'temporal'],
     *     'author' => 'Glueful Team'
     * ]
     *
     * @return array Provider metadata
     */
    public function getProviderInfo(): array;

    /**
     * Test the provider connection/functionality
     *
     * Perform a health check of the permission provider.
     * Should verify database connections, cache availability, etc.
     *
     * @return array Health check results with status and details
     */
    public function healthCheck(): array;
}
