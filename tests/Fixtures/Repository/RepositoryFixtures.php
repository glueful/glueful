<?php
declare(strict_types=1);

namespace Tests\Fixtures\Repository;

/**
 * Repository Test Fixtures
 * 
 * Provides sample data for repository unit tests
 */
class RepositoryFixtures
{
    /**
     * Get sample user data
     * 
     * @param bool $withId Whether to include ID field
     * @return array Sample user data
     */
    public static function getSampleUserData(bool $withId = true): array
    {
        $data = [
            'uuid' => '12345678-1234-1234-1234-123456789012',
            'username' => 'testuser',
            'email' => 'test@example.com',
            'password' => '$2y$10$abcdefghijklmnopqrstuv.abcdefghijklmnopqrstuvwxyz012345',
            'status' => 'active',
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00'
        ];
        
        if ($withId) {
            $data['id'] = 1;
        }
        
        return $data;
    }
    
    /**
     * Get sample user profile data
     * 
     * @return array Sample profile data
     */
    public static function getSampleUserProfileData(): array
    {
        return [
            'user_uuid' => '12345678-1234-1234-1234-123456789012',
            'first_name' => 'Test',
            'last_name' => 'User',
            'photo_uuid' => '87654321-4321-4321-4321-210987654321',
            'photo_url' => 'https://example.com/photos/user.jpg',
            'created_at' => '2023-01-01 00:00:00',
            'updated_at' => '2023-01-01 00:00:00'
        ];
    }
    
    /**
     * Get sample role data
     * 
     * @return array Array of sample roles
     */
    public static function getSampleRoleData(): array
    {
        return [
            [
                'uuid' => '11111111-1111-1111-1111-111111111111',
                'name' => 'admin',
                'description' => 'Administrator role'
            ],
            [
                'uuid' => '22222222-2222-2222-2222-222222222222',
                'name' => 'editor',
                'description' => 'Editor role'
            ],
            [
                'uuid' => '33333333-3333-3333-3333-333333333333',
                'name' => 'user',
                'description' => 'Standard user role'
            ]
        ];
    }
    
    /**
     * Get sample permissions data
     * 
     * @return array Array of sample permissions
     */
    public static function getSamplePermissionsData(): array
    {
        return [
            [
                'permission' => 'users:read',
                'description' => 'Read user data'
            ],
            [
                'permission' => 'users:write',
                'description' => 'Modify user data'
            ],
            [
                'permission' => 'users:delete',
                'description' => 'Delete user data'
            ],
            [
                'permission' => 'settings:read',
                'description' => 'Read system settings'
            ],
            [
                'permission' => 'settings:write',
                'description' => 'Modify system settings'
            ]
        ];
    }
    
    /**
     * Get sample notification data
     * 
     * @return array Array of sample notifications
     */
    public static function getSampleNotificationsData(): array
    {
        return [
            [
                'id' => 1,
                'uuid' => '12345678-1234-1234-1234-123456789012',
                'type' => 'account_created',
                'subject' => 'Welcome to Glueful',
                'data' => json_encode(['key' => 'value']),
                'priority' => 'normal',
                'notifiable_type' => 'user',
                'notifiable_id' => 'user-uuid',
                'read_at' => null,
                'created_at' => '2023-01-01 00:00:00',
                'updated_at' => '2023-01-01 00:00:00'
            ],
            [
                'id' => 2,
                'uuid' => '22222222-2222-2222-2222-222222222222',
                'type' => 'password_changed',
                'subject' => 'Your password was changed',
                'data' => json_encode(['ip' => '192.168.1.1']),
                'priority' => 'high',
                'notifiable_type' => 'user',
                'notifiable_id' => 'user-uuid',
                'read_at' => null,
                'created_at' => '2023-01-02 00:00:00',
                'updated_at' => '2023-01-02 00:00:00'
            ]
        ];
    }
    
    /**
     * Get sample notification preferences data
     * 
     * @return array Array of sample notification preferences
     */
    public static function getSamplePreferencesData(): array
    {
        return [
            [
                'id' => 1,
                'notifiable_type' => 'user',
                'notifiable_id' => 'user-uuid',
                'channel' => 'email',
                'notification_type' => 'account_security',
                'enabled' => 1
            ],
            [
                'id' => 2,
                'notifiable_type' => 'user',
                'notifiable_id' => 'user-uuid',
                'channel' => 'push',
                'notification_type' => 'marketing',
                'enabled' => 0
            ]
        ];
    }
}
