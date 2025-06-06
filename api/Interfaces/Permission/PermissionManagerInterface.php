<?php

declare(strict_types=1);

namespace Glueful\Interfaces\Permission;

/**
 * Permission Manager Interface
 *
 * Facade interface for the permission management system.
 * This provides a simplified, consistent API for the core framework
 * to interact with the permission system, regardless of which
 * permission provider is active.
 *
 * The Permission Manager acts as a proxy to the active permission
 * provider and handles provider registration, configuration, and
 * fallback behaviors.
 *
 * @package Glueful\Interfaces\Permission
 */
interface PermissionManagerInterface
{
    /**
     * Set the active permission provider
     *
     * Registers and activates a permission provider for use by the system.
     * Only one provider can be active at a time.
     *
     * @param PermissionProviderInterface $provider The provider to activate
     * @param array $config Configuration for the provider
     * @return void
     * @throws \Exception If provider initialization fails
     */
    public function setProvider(PermissionProviderInterface $provider, array $config = []): void;

    /**
     * Get the current active permission provider
     *
     * Returns the currently active permission provider instance.
     * Useful for debugging or advanced operations.
     *
     * @return PermissionProviderInterface|null Current provider or null if none set
     */
    public function getProvider(): ?PermissionProviderInterface;

    /**
     * Check if a user has permission (simplified facade method)
     *
     * Primary method used throughout the framework for permission checks.
     * Delegates to the active provider's can() method.
     *
     * @param string $userUuid User UUID to check permissions for
     * @param string $permission Permission name (e.g., 'view', 'edit')
     * @param string $resource Resource identifier (e.g., 'posts', 'users')
     * @param array $context Additional context for permission check
     * @return bool True if user has permission, false otherwise
     * @throws \Exception If no provider is registered
     */
    public function can(string $userUuid, string $permission, string $resource, array $context = []): bool;

    /**
     * Check if a user has permission using token
     *
     * Convenience method that extracts user UUID from an authentication token
     * and then performs the permission check.
     *
     * @param string $token Authentication token
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return bool True if user has permission, false otherwise
     * @throws \Exception If token is invalid or no provider is registered
     */
    public function canWithToken(string $token, string $permission, string $resource, array $context = []): bool;

    /**
     * Get all permissions for a user
     *
     * Facade method that delegates to the active provider.
     *
     * @param string $userUuid User UUID to get permissions for
     * @return array User's permissions
     * @throws \Exception If no provider is registered
     */
    public function getUserPermissions(string $userUuid): array;

    /**
     * Assign permission to user
     *
     * Facade method for permission assignment.
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @param array $options Assignment options
     * @return bool Success status
     * @throws \Exception If no provider is registered
     */
    public function assignPermission(string $userUuid, string $permission, string $resource, array $options = []): bool;

    /**
     * Revoke permission from user
     *
     * Facade method for permission revocation.
     *
     * @param string $userUuid User UUID
     * @param string $permission Permission name
     * @param string $resource Resource identifier
     * @return bool Success status
     * @throws \Exception If no provider is registered
     */
    public function revokePermission(string $userUuid, string $permission, string $resource): bool;

    /**
     * Check if the permission system is available
     *
     * Returns true if a permission provider is registered and functional.
     * Useful for graceful degradation when permissions are optional.
     *
     * @return bool True if permission system is available
     */
    public function isAvailable(): bool;

    /**
     * Get system information
     *
     * Returns information about the permission system state,
     * including active provider details and health status.
     *
     * @return array System information
     */
    public function getSystemInfo(): array;

    /**
     * Invalidate user permission cache
     *
     * Clears cached permissions for a specific user.
     *
     * @param string $userUuid User UUID to invalidate
     * @return void
     */
    public function invalidateUserCache(string $userUuid): void;

    /**
     * Invalidate all permission caches
     *
     * Clears all cached permission data.
     *
     * @return void
     */
    public function invalidateAllCache(): void;

    /**
     * Enable debug mode
     *
     * Enables detailed logging and debugging information
     * for permission checks and operations.
     *
     * @param bool $enabled Whether to enable debug mode
     * @return void
     */
    public function setDebugMode(bool $enabled): void;

    /**
     * Get debug information
     *
     * Returns detailed information about recent permission
     * operations for debugging purposes.
     *
     * @return array Debug information
     */
    public function getDebugInfo(): array;

    /**
     * Perform health check
     *
     * Checks the health of the permission system including
     * provider availability and functionality.
     *
     * @return array Health check results
     */
    public function healthCheck(): array;

    /**
     * Register multiple providers
     *
     * Allows registration of multiple providers for fallback
     * or selection purposes. Only one can be active at a time.
     *
     * @param array $providers Array of provider instances
     * @return void
     */
    public function registerProviders(array $providers): void;

    /**
     * Switch active provider
     *
     * Switches from one registered provider to another.
     * Useful for testing or runtime provider switching.
     *
     * @param string $providerName Name of registered provider to activate
     * @param array $config Configuration for the new provider
     * @return bool True if switch successful
     */
    public function switchProvider(string $providerName, array $config = []): bool;

    /**
     * Get available providers
     *
     * Returns list of registered permission providers.
     *
     * @return array List of available providers
     */
    public function getAvailableProviders(): array;
}
