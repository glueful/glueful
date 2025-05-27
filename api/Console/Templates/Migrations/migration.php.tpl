<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

/**
 * {{MIGRATION_DESCRIPTION}}
 *
 * @package Glueful\Database\Migrations
 */
class {{CLASS_NAME}} implements MigrationInterface
{
    /**
     * Execute the migration
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        // Create your table here
        // Example:
        // $schema->createTable('{{TABLE_NAME}}', [
        //     'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
        //     'name' => 'VARCHAR(255) NOT NULL',
        //     'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
        //     'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        // ])->addIndex([
        //     ['type' => 'UNIQUE', 'column' => 'name']
        // ]);
    }

    /**
     * Reverse the migration
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        // Drop your table here
        // $schema->dropTable('{{TABLE_NAME}}');
    }

    /**
     * Get migration description
     *
     * @return string Migration description
     */
    public function getDescription(): string
    {
        return '{{MIGRATION_DESCRIPTION}}';
    }
}