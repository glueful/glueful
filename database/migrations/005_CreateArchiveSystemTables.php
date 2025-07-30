<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

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
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function up(SchemaBuilderInterface $schema): void
    {
        // Create Archive Registry Table
        $schema->createTable('archive_registry', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('table_name', 64);
            $table->date('archive_date');
            $table->dateTime('period_start');
            $table->dateTime('period_end');
            $table->integer('record_count')->unsigned();
            $table->string('file_path', 500);
            $table->bigInteger('file_size')->unsigned();
            $table->enum('compression_type', ['gzip', 'bzip2', 'lz4'], 'gzip');
            $table->boolean('encryption_enabled')->default(true);
            $table->string('checksum_sha256', 64);
            $table->enum('status', ['creating', 'completed', 'verified', 'corrupted', 'failed'], 'creating');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            // Add indexes
            $table->unique('uuid');
            $table->index('table_name');
            $table->index('archive_date');
            $table->index('status');
            $table->index('period_start');
            $table->index('period_end');
        });

        // Create Archive Search Index Table
        $schema->createTable('archive_search_index', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('archive_uuid', 12);
            $table->string('entity_type', 50);
            $table->string('entity_value', 255);
            $table->integer('record_count')->unsigned();
            $table->dateTime('first_occurrence');
            $table->dateTime('last_occurrence');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->index('archive_uuid');
            $table->index('entity_type');
            $table->index('entity_value');
            $table->index('first_occurrence');
            $table->index('last_occurrence');

            // Add foreign key
            $table->foreign('archive_uuid')
                ->references('uuid')
                ->on('archive_registry')
                ->cascadeOnDelete();
        });

        // Create Archive Table Stats Table
        $schema->createTable('archive_table_stats', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('table_name', 64);
            $table->bigInteger('current_size_bytes')->unsigned()->default(0);
            $table->integer('current_row_count')->unsigned()->default(0);
            $table->date('last_archive_date')->nullable();
            $table->date('next_archive_date')->nullable();
            $table->integer('archive_threshold_rows')->unsigned()->default(100000);
            $table->integer('archive_threshold_days')->unsigned()->default(30);
            $table->boolean('auto_archive_enabled')->default(true);
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            // Add indexes
            $table->unique('table_name');
            $table->index('last_archive_date');
            $table->index('next_archive_date');
            $table->index('auto_archive_enabled');
        });
    }

    /**
     * Reverse the migration
     *
     * Drops all archive system tables in correct order
     * to maintain referential integrity
     *
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('archive_search_index');
        $schema->dropTableIfExists('archive_table_stats');
        $schema->dropTableIfExists('archive_registry');
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
