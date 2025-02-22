<?php

use Glueful\App\Migrations\MigrationInterface;
use Glueful\Api\Schemas\SchemaManager;
use Glueful\Api\Library\Utils;

class SeedInitialData implements MigrationInterface
{
    private SchemaManager $schema;

    public function up(SchemaManager $schema): void
    {
        $this->schema = $schema;

        try {
            // First create admin role
            $adminRoleUuid = Utils::generateNanoID(12);
            $adminId = $this->schema->insert('roles', [
                'uuid' => $adminRoleUuid,
                'name' => 'Administrator',
                'description' => 'Full system access',
                'status' => 'active'
            ]);
            
            if (!$adminId) {
                throw new \RuntimeException('Failed to create admin role');
            }

            // Create user role
            $userRoleUuid = Utils::generateNanoID(12);
            $userId=$this->schema->insert('roles', [
                'uuid' => $userRoleUuid,
                'name' => 'User',
                'description' => 'Standard user access',
                'status' => 'active'
            ]);
            
            if (!$userId) {
                throw new \RuntimeException('Failed to create user role');
            }

            // Then create admin user
            $adminUserUuid = Utils::generateNanoID(12);
            $adminUserId = $this->schema->insert('users', [
                'uuid' => $adminUserUuid,
                'username' => 'admin',
                'email' => 'admin@example.com',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'status' => 'active'
            ]);

            if (!$adminUserId) {
                throw new \RuntimeException('Failed to create admin user');
            }

            // Then create profile for admin user
            $profileUuid = Utils::generateNanoID(12);
            $profileId = $this->schema->insert('profiles', [
                'uuid' => $profileUuid,
                'user_uuid' => $adminUserUuid,
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'status' => 'active'
            ]);

            if (!$profileId) {
                throw new \RuntimeException('Failed to create admin profile');
            }

            // Finally create role mapping
            $mappingId = $this->schema->insert('user_roles_lookup', [
                'user_uuid' => $adminUserUuid,
                'role_uuid' => $adminRoleUuid
            ]);

            if (!$mappingId) {
                throw new \RuntimeException('Failed to create role mapping');
            }

        } catch (\Exception $e) {
            throw new \RuntimeException('Seeding failed: ' . $e->getMessage());
        }
    }

    public function down(SchemaManager $schema): void
    {
        $this->schema = $schema;
        
        // Delete in reverse order of dependencies
        $this->schema->delete('profiles', ['first_name' => 'System', 'last_name' => 'Administrator']);
        $this->schema->delete('permissions', ['model' => '*']);
        $this->schema->delete('user_roles_lookup', ['user_uuid' => ['username' => 'admin']]);
        $this->schema->delete('users', ['username' => 'admin']);
        $this->schema->delete('roles', ['name' => ['Administrator', 'User']]);
    }

    public function getDescription(): string
    {
        return 'Seeds initial data including admin user, roles, and permissions';
    }
}
