<?php

/**
 * User Fixtures for testing
 *
 * This file contains sample users for testing.
 * These are loaded by tests that require user data.
 */

namespace Tests\Fixtures;

use Glueful\Auth\PasswordHasher;
use Glueful\Database\QueryBuilder;

class UserFixtures
{
    /**
     * Load user fixtures into the database
     */
    public static function load(QueryBuilder $db)
    {
        // Create test users
        $passwordHasher = new PasswordHasher();

        // Admin user
        $db->insert('users', [
            'uuid' => '12345678-1234-1234-1234-123456789abc',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'password' => $passwordHasher->hash('admin123'),
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Regular user
        $db->insert('users', [
            'uuid' => '87654321-4321-4321-4321-cba987654321',
            'username' => 'user',
            'email' => 'user@example.com',
            'password' => $passwordHasher->hash('user123'),
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Inactive user
        $db->insert('users', [
            'uuid' => 'abcdef12-5678-90ab-cdef-123456789abc',
            'username' => 'inactive',
            'email' => 'inactive@example.com',
            'password' => $passwordHasher->hash('inactive123'),
            'status' => 'inactive',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }
}
