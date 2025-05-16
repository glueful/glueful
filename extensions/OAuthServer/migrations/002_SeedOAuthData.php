<?php

namespace Glueful\Extensions\OAuthServer\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * OAuth Server Initial Data Seeder
 *
 * Creates essential OAuth system data in correct order:
 * - Default OAuth scopes
 * - System client for internal use
 *
 * Security Configuration:
 * - OAuth scope definitions
 * - Default client credentials
 * - Client secret management
 *
 * Data Dependencies:
 * - Requires oauth_scopes table
 * - Requires oauth_clients table
 *
 * @package Glueful\Extensions\OAuthServer\Migrations
 */
class SeedOAuthData implements MigrationInterface
{
    /** @var QueryBuilder Database interaction instance */
    private QueryBuilder $db;

    /**
     * Execute OAuth data seeding
     *
     * Creates OAuth system data in sequence:
     * 1. Create default OAuth scopes
     * 2. Create system OAuth client
     *
     * Default Scopes:
     * - basic: Account access
     * - profile: Profile information
     * - email: Email address access
     * - admin: Administrative access
     * - read: Read resource access
     * - write: Write resource access
     *
     * System Client:
     * - ID: Automatically generated
     * - Secret: Automatically generated
     * - Grant Types: client_credentials, password, refresh_token
     *
     * @param SchemaManager $schema Database schema manager
     * @throws \RuntimeException If seeding fails
     */
    public function up(SchemaManager $schema): void
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        try {
            // Create default OAuth scopes
            $this->createDefaultScopes();

            // Create default system client
            $this->createDefaultClient();
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to seed OAuth data: ' . $e->getMessage());
        }
    }

    /**
     * Reverse the migration
     *
     * Removes all seeded OAuth data:
     * - Cleans scopes
     * - Removes system client
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        $connection = new Connection();
        $this->db = new QueryBuilder($connection->getPDO(), $connection->getDriver());

        // Delete default system client
        $this->db->delete('oauth_clients', ['is_default' => 1]);

        // Delete all predefined scopes
        $this->db->delete('oauth_scopes', ['identifier' => 'basic']);
        $this->db->delete('oauth_scopes', ['identifier' => 'profile']);
        $this->db->delete('oauth_scopes', ['identifier' => 'email']);
        $this->db->delete('oauth_scopes', ['identifier' => 'admin']);
        $this->db->delete('oauth_scopes', ['identifier' => 'read']);
        $this->db->delete('oauth_scopes', ['identifier' => 'write']);
    }

    /**
     * Create default scopes
     *
     * Creates predefined OAuth scopes for:
     * - Basic account access
     * - Profile information access
     * - Email access
     * - Administrative access
     * - Read/write operations
     *
     * @throws \RuntimeException If scope creation fails
     */
    private function createDefaultScopes(): void
    {
        $defaultScopes = [
            [
                'identifier' => 'basic',
                'name' => 'Basic',
                'description' => 'Basic access to account information',
                'is_default' => 1
            ],
            [
                'identifier' => 'profile',
                'name' => 'Profile',
                'description' => 'Access to profile information',
                'is_default' => 0
            ],
            [
                'identifier' => 'email',
                'name' => 'Email',
                'description' => 'Access to email address',
                'is_default' => 0
            ],
            [
                'identifier' => 'admin',
                'name' => 'Admin',
                'description' => 'Administrative access',
                'is_default' => 0
            ],
            [
                'identifier' => 'read',
                'name' => 'Read',
                'description' => 'Read access to resources',
                'is_default' => 1
            ],
            [
                'identifier' => 'write',
                'name' => 'Write',
                'description' => 'Write access to resources',
                'is_default' => 0
            ]
        ];

        foreach ($defaultScopes as $scope) {
            $result = $this->db->insert('oauth_scopes', $scope);
            if (!$result) {
                throw new \RuntimeException("Failed to create OAuth scope: {$scope['identifier']}");
            }
        }
    }

    /**
     * Create default client for system use
     *
     * Creates a system OAuth client with:
     * - Secure randomly generated ID and secret
     * - Default client status
     * - Standard grant types (client_credentials, password, refresh_token)
     * - Local callback URL for development
     *
     * @throws \RuntimeException If client creation fails
     */
    private function createDefaultClient(): void
    {
        $clientId = 'system_client_' . bin2hex(random_bytes(4));
        $clientSecret = bin2hex(random_bytes(32));
        $now = time();

        $result = $this->db->insert('oauth_clients', [
            'id' => $clientId,
            'name' => 'System Client',
            'description' => 'Default system client for internal use',
            'redirect_uris' => json_encode(['https://localhost/callback']),
            'allowed_grant_types' => json_encode(['client_credentials', 'password', 'refresh_token']),
            'is_confidential' => 1,
            'secret' => password_hash($clientSecret, PASSWORD_DEFAULT),
            'is_default' => 1,
            'created_at' => $now,
            'updated_at' => $now
        ]);

        if (!$result) {
            throw new \RuntimeException('Failed to create default system client');
        }

        // Output client credentials to migration log
        echo "Created default system client: {$clientId}" . PHP_EOL;
        echo "Client secret: {$clientSecret} (save this, it won't be shown again)" . PHP_EOL;
    }

    /**
     * Get migration description
     *
     * Provides human-readable description of:
     * - Migration purpose
     * - Major changes
     * - System impacts
     *
     * @return string Migration description
     */
    public function getDescription(): string
    {
        return 'Seeds initial OAuth data including default scopes and system client';
    }
}
