<?php

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Helpers\Utils;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * Initial System Data Seeder
 * 
 * Creates essential system data in correct order:
 * - Core roles (superuser, standard user)
 * - Default superuser account
 * - Profile information
 * - Role assignments
 * 
 * Security Configuration:
 * - Default credentials setup
 * - Role hierarchy establishment 
 * - Permission structure
 * - Access control initialization
 * 
 * Data Dependencies:
 * - Requires roles table
 * - Requires users table
 * - Requires profiles table
 * - Requires role_lookup table
 * 
 * @package Glueful\Database\Migrations
 */
class SeedInitialData implements MigrationInterface
{
    /** @var QueryBuilder Database interaction instance */
    private QueryBuilder $db;

    /**
     * Execute initial data seeding
     * 
     * Creates system data in sequence:
     * 1. Create superuser role
     * 2. Create standard user role
     * 3. Create superuser account
     * 4. Create superuser profile
     * 5. Link role assignments
     * 
     * Default Superuser:
     * - Username: superuser
     * - Email: superuser@glueful.com
     * - Password: superuser123
     * 
     * @param SchemaManager $schema Database schema manager
     * @throws \RuntimeException If seeding fails
     */
    public function up(SchemaManager $schema): void
    {
        $conection = new Connection();
        $this->db = new QueryBuilder($conection->getPDO(), $conection->getDriver());

        try {
            // First create superuser role
            $superuserRoleUuid = Utils::generateNanoID();
            $superuserId = $this->db->insert('roles', [
                'uuid' => $superuserRoleUuid,
                'name' => 'superuser',
                'description' => 'Full system access',
                'status' => 'active'
            ]);
            
            if (!$superuserId) {
                throw new \RuntimeException('Failed to create "superuser" role');
            }

            // Create user role
            $userRoleUuid = Utils::generateNanoID();
            $userId=$this->db->insert('roles', [
                'uuid' => $userRoleUuid,
                'name' => 'user',
                'description' => 'Standard user access',
                'status' => 'active'
            ]);
            
            if (!$userId) {
                throw new \RuntimeException('Failed to create user role');
            }

            // Then create "superuser" user
            $superuserUuid = Utils::generateNanoID();
            $superuserId = $this->db->insert('users', [
                'uuid' => $superuserUuid,
                'username' => 'superuser',
                'email' => 'superuser@glueful.com',
                'password' => password_hash('superuser123', PASSWORD_DEFAULT),
                'status' => 'active'
            ]);

            if (!$superuserId) {
                throw new \RuntimeException('Failed to create "superuser" user');
            }

            // Then create profile for "superuser" user
            $profileUuid = Utils::generateNanoID();
            $profileId = $this->db->insert('profiles', [
                'uuid' => $profileUuid,
                'user_uuid' => $superuserUuid,
                'first_name' => 'Super',
                'last_name' => 'User',
                'status' => 'active'
            ]);

            if (!$profileId) {
                throw new \RuntimeException('Failed to create "superuser" profile');
            }

            // Finally create role mapping
            $mappingId = $this->db->insert('user_roles_lookup', [
                'user_uuid' => $superuserUuid,
                'role_uuid' => $superuserRoleUuid
            ]);

            if (!$mappingId) {
                throw new \RuntimeException('Failed to create role mapping');
            }

        } catch (\Exception $e) {
            throw new \RuntimeException('Seeding failed: ' . $e->getMessage());
        }
    }

    /**
     * Revert seeded data
     * 
     * Removes initial data in dependency order:
     * 1. Remove profile data first
     * 2. Remove role assignments
     * 3. Remove user accounts
     * 4. Remove roles last
     * 
     * Cleanup Process:
     * - Maintains referential integrity
     * - Complete removal of seed data
     * - Preserves custom data
     * 
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        
        // Delete in reverse order of dependencies
        $this->db->delete('profiles', ['first_name' => 'Super', 'last_name' => 'User']);
        $this->db->delete('user_roles_lookup', ['user_uuid' => ['username' => 'superuser']]);
        $this->db->delete('users', ['username' => 'superuser']);
        $this->db->delete('roles', ['name' => ['superuser', 'user']]);
    }

    /**
     * Get seeder description
     * 
     * Documents:
     * - Initial data creation
     * - Default accounts
     * - System roles
     * 
     * @return string Human-readable description
     */
    public function getDescription(): string
    {
        return 'Seeds initial data including "superuser" user, profiles and roles';
    }
}
