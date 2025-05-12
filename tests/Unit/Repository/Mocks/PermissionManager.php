<?php
namespace Glueful\Permissions;

/**
 * Mock PermissionManager Class
 * 
 * This is a mock implementation for testing purposes.
 */
class PermissionManager
{
    /**
     * Static method to invalidate permission cache
     * This is a mock that does nothing during tests
     * 
     * @param string $userUuid User UUID to invalidate cache for
     * @return void
     */
    public static function invalidateCache(string $userUuid): void
    {
        // Do nothing in test
    }
}
