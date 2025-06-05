<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

/**
 * Archive System Database Migration
 *
 * Creates archive system tables for data lifecycle management:
 * - Archive registry for tracking archived data
 * - Search indexes for archived data lookup
 * - Table statistics for archive monitoring
 *
 * Database Design:
 * - Supports compressed and encrypted archives
 * - Enables fast search across archived data
 * - Tracks table growth and archive schedules
 * - Maintains data integrity with checksums
 *
 * Archive Features:
 * - Automatic data lifecycle management
 * - Search and retrieval capabilities
 * - Performance monitoring
 * - Compliance support
 *
 * @package Glueful\Database\Migrations
 */
class CreateArchiveSystemTables implements MigrationInterface
{
    /**
     * Execute the migration
     *
     * Creates archive system tables with:
     * - Archive registry for metadata tracking
     * - Search indexes for archived data lookup
     * - Table statistics for monitoring
     * - Proper foreign key relationships
     *
     * Tables created:
     * - archive_registry: Archive metadata and file tracking
     * - archive_search_index: Search indexes for archived data
     * - archive_table_stats: Table growth and archive scheduling
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        // Create Archive Registry Table
        $schema->createTable('archive_registry', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'table_name' => 'VARCHAR(64) NOT NULL',
            'archive_date' => 'DATE NOT NULL',
            'period_start' => 'DATETIME NOT NULL',
            'period_end' => 'DATETIME NOT NULL',
            'record_count' => 'INT UNSIGNED NOT NULL',
            'file_path' => 'VARCHAR(500) NOT NULL',
            'file_size' => 'BIGINT UNSIGNED NOT NULL',
            'compression_type' => "ENUM('gzip', 'bzip2', 'lz4') DEFAULT 'gzip'",
            'encryption_enabled' => 'BOOLEAN DEFAULT TRUE',
            'checksum_sha256' => 'CHAR(64) NOT NULL',
            'status' => "ENUM('creating', 'completed', 'verified', 'corrupted', 'failed') DEFAULT 'creating'",
            'metadata' => 'JSON',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'table_name'],
            ['type' => 'INDEX', 'column' => 'archive_date'],
            ['type' => 'INDEX', 'column' => 'status'],
            ['type' => 'INDEX', 'columns' => ['table_name', 'archive_date']],
            ['type' => 'INDEX', 'columns' => ['period_start', 'period_end']]
        ]);

        // Create Archive Search Index Table
        $schema->createTable('archive_search_index', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'archive_uuid' => 'CHAR(12) NOT NULL',
            'entity_type' => 'VARCHAR(50) NOT NULL',
            'entity_value' => 'VARCHAR(255) NOT NULL',
            'record_count' => 'INT UNSIGNED NOT NULL',
            'first_occurrence' => 'DATETIME NOT NULL',
            'last_occurrence' => 'DATETIME NOT NULL',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'INDEX', 'column' => 'archive_uuid'],
            ['type' => 'INDEX', 'columns' => ['entity_type', 'entity_value']],
            ['type' => 'INDEX', 'columns' => ['first_occurrence', 'last_occurrence']]
        ])->addForeignKey([
            [
                'column' => 'archive_uuid',
                'references' => 'uuid',
                'on' => 'archive_registry',
                'onDelete' => 'CASCADE'
            ]
        ]);

        // Create Archive Table Stats Table
        $schema->createTable('archive_table_stats', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'table_name' => 'VARCHAR(64) NOT NULL',
            'current_size_bytes' => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'current_row_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
            'last_archive_date' => 'DATE NULL',
            'next_archive_date' => 'DATE NULL',
            'archive_threshold_rows' => 'INT UNSIGNED NOT NULL DEFAULT 100000',
            'archive_threshold_days' => 'INT UNSIGNED NOT NULL DEFAULT 30',
            'auto_archive_enabled' => 'BOOLEAN DEFAULT TRUE',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'table_name'],
            ['type' => 'INDEX', 'column' => 'last_archive_date'],
            ['type' => 'INDEX', 'column' => 'next_archive_date'],
            ['type' => 'INDEX', 'column' => 'auto_archive_enabled']
        ]);
    }

    /**
     * Reverse the migration
     *
     * Drops all archive system tables in correct order
     * to maintain referential integrity
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        $schema->dropTable('archive_search_index');
        $schema->dropTable('archive_table_stats');
        $schema->dropTable('archive_registry');
    }

    /**
     * Get migration description
     *
     * @return string Description of what this migration does
     */
    public function getDescription(): string
    {
        return 'Creates archive system tables for data lifecycle management including archive registry, ' .
               'search indexes, and table statistics';
    }
}
