<?php
declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

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
                'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
                'uuid' => 'CHAR(12) NOT NULL',
                'user_uuid' => 'CHAR(12) NOT NULL', 
                'provider' => 'VARCHAR(50) NOT NULL',
                'social_id' => 'VARCHAR(255) NOT NULL',
                'profile_data' => 'TEXT NULL',
                'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            ])->addIndex([
                ['type' => 'UNIQUE', 'column' => 'uuid', 'table' => 'social_accounts'],
                ['type' => 'INDEX', 'column' => 'user_uuid', 'table' => 'social_accounts'],
                ['type' => 'FOREIGN KEY', 'column' => 'user_uuid', 'table' => 'social_accounts', 'references' => 'uuid', 'on' => 'users', 'onDelete' => 'CASCADE']
            ]);
            
            // Add composite unique index on provider and social_id
            $schema->addIndex([
                'table' => 'social_accounts',
                'type' => 'UNIQUE',
                'columns' => ['provider', 'social_id'],
                'name' => 'idx_social_provider_id'
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