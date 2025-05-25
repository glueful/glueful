<?php

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Helpers\Utils;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * Initial System Data Seeder
 *
 * Creates essential system roles:
 * - Core roles (superuser, standard user)
 * - Role hierarchy establishment
 * - Permission structure foundation
 * - Access control initialization
 *
 * Note: Admin user creation is handled by the installation wizard
 * for better security and customization.
 *
 * Data Dependencies:
 * - Requires roles table
 *
 * @package Glueful\Database\Migrations
 */
class SeedInitialData implements MigrationInterface
{
    /** @var QueryBuilder Database interaction instance */
    private QueryBuilder $db;

    /**
     * Execute initial role seeding
     *
     * Creates essential system roles:
     * 1. Create superuser role (full system access)
     * 2. Create standard user role (basic access)
     *
     * Admin user creation is handled by the installation wizard
     * for better security and customization.
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

            // Create standard user role
            $userRoleUuid = Utils::generateNanoID();
            $userRoleId = $this->db->insert('roles', [
                'uuid' => $userRoleUuid,
                'name' => 'user',
                'description' => 'Standard user access',
                'status' => 'active'
            ]);

            if (!$userRoleId) {
                throw new \RuntimeException('Failed to create "user" role');
            }
        } catch (\Exception $e) {
            throw new \RuntimeException('Role seeding failed: ' . $e->getMessage());
        }
    }

    /**
     * Revert seeded roles
     *
     * Removes created roles:
     * - Remove superuser role
     * - Remove user role
     *
     * Note: User data is managed separately by the installation wizard
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        // Delete only the roles created by this migration
        $this->db->delete('roles', ['name' => 'superuser'], false);
        $this->db->delete('roles', ['name' => 'user'], false);
    }

    /**
     * Get seeder description
     *
     * Documents:
     * - System roles creation
     * - Permission structure setup
     *
     * @return string Human-readable description
     */
    public function getDescription(): string
    {
        return 'Seeds essential system roles (superuser, user)';
    }
}
