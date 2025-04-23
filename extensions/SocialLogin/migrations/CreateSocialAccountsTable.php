<?php
declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;
use Glueful\Helpers\Utils;

/**
 * Social Accounts Migration
 * 
 * Creates the social_accounts table for storing linked social login accounts.
 */
class CreateSocialAccountsTable implements MigrationInterface
{
    /**
     * Apply migration
     * 
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        // Check if the table exists by getting all tables and checking if social_accounts is in the array
        $tables = $schema->getTables();
        if (!in_array('social_accounts', $tables)) {
            $schema->createTable('social_accounts', [
                'uuid' => [
                    'type' => 'CHAR(21)',
                    'nullable' => false,
                    'primary' => true
                ],
                'user_uuid' => [
                    'type' => 'CHAR(21)', 
                    'nullable' => false,
                    'references' => [
                        'table' => 'users',
                        'column' => 'uuid',
                        'onDelete' => 'CASCADE'
                    ]
                ],
                'provider' => [
                    'type' => 'VARCHAR(50)',
                    'nullable' => false,
                    'comment' => 'Social provider name (google, facebook, github, etc.)'
                ],
                'social_id' => [
                    'type' => 'VARCHAR(255)',
                    'nullable' => false,
                    'comment' => 'User ID from the social provider'
                ],
                'profile_data' => [
                    'type' => 'TEXT',
                    'nullable' => true,
                    'comment' => 'JSON-encoded social profile data'
                ],
                'created_at' => [
                    'type' => 'TIMESTAMP',
                    'nullable' => false,
                    'default' => 'CURRENT_TIMESTAMP'
                ],
                'updated_at' => [
                    'type' => 'TIMESTAMP',
                    'nullable' => false,
                    'default' => 'CURRENT_TIMESTAMP'
                ]
            ])
            ->addIndex([
                'columns' => ['user_uuid'],
                'name' => 'idx_social_accounts_user_uuid'
            ])
            ->addIndex([
                'columns' => ['provider', 'social_id'],
                'name' => 'idx_social_accounts_provider_id',
                'unique' => true
            ]);
        }
    }
    
    /**
     * Revert migration
     * 
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        // Check if the table exists by getting all tables and checking if social_accounts is in the array
        $tables = $schema->getTables();
        if (in_array('social_accounts', $tables)) {
            $schema->dropTable('social_accounts');
        }
    }
    
    /**
     * Get migration description
     * 
     * @return string Migration description
     */
    public function getDescription(): string
    {
        return "Create social_accounts table for social login integration";
    }
}