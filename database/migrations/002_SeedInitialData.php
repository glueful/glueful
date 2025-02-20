<?php

use Glueful\App\Database\Migrations\MigrationInterface;
use Glueful\App\Database\Schemas\SchemaManager;
use Glueful\App\Database\Schemas\SchemaManagerFactory;
use Glueful\Api\Library\Utils;

class SeedInitialData implements MigrationInterface
{
    private SchemaManager $schemaManager;
    private PDO $db;

    public function __construct()
    {
        $this->db = SchemaManagerFactory::create();
    }

    public function up(SchemaManager $schema): void
    {
        // Use injected schema manager for structural changes
        $this->schemaManager = $schema;
        
        // Seed Roles with generated UUIDs
        $adminUuid = Utils::generateNanoID(12);
        $userUuid = Utils::generateNanoID(12);
        
        $this->db->exec("
            INSERT INTO roles (uuid, name, description, status) VALUES 
                ('$adminUuid', 'Administrator', 'Full system access with all privileges', 'active'),
                ('$userUuid', 'User', 'Standard user access with basic privileges', 'active')
            ON DUPLICATE KEY UPDATE id=id
        ");

        // Get Admin role ID for permissions
        $adminRoleId = $this->db->query("SELECT id FROM roles WHERE name = 'Administrator'")->fetchColumn();

        // Create Admin User
        // Seed Admin User with generated UUIDs
        $adminUserUuid = Utils::generateNanoID(12);


        $this->db->exec("
            INSERT INTO users (uuid, username, email, password, status)
            VALUES (
                '$adminUserUuid',
                'admin',
                'admin@example.com',
                '" . password_hash('admin123', PASSWORD_BCRYPT) . "',
                'active'
            )
        ");

        // Assign Admin Role
        $this->db->exec("
            INSERT INTO user_roles_lookup (user_uuid, role_id)
            VALUES ('$adminUserUuid', $adminRoleId)
        ");
    }

    public function down(SchemaManager $schema): void
    {
        // Use injected schema manager for structural changes
        $this->schemaManager = $schema;
        
        // Use factory-created connection for data operations
        $this->db->exec("DELETE FROM user_roles_lookup");
        $this->db->exec("DELETE FROM role_permissions");
        $this->db->exec("DELETE FROM permissions");
        $this->db->exec("DELETE FROM users WHERE username = 'admin'");
        $this->db->exec("DELETE FROM roles WHERE name = 'Administrator'");
    }

    public function getDescription(): string
    {
        return 'Seeds initial data including admin user, roles, and permissions';
    }
}
