<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Queue System Database Schema Migration
 *
 * Creates essential queue system tables for async job processing:
 * - Queue jobs storage and processing
 * - Failed job tracking and retry management
 * - Batch processing for grouped operations
 *
 * Database Design:
 * - Optimized for high-throughput job processing
 * - Implements proper indexing for queue operations
 * - Supports multiple queue drivers (database, redis, beanstalkd)
 * - Handles job priorities and delayed execution
 * - Minimal table structure for maximum performance
 *
 * Performance Features:
 * - Composite indexes for efficient job retrieval
 * - Atomic job reservation with timestamps
 * - Priority-based job ordering
 * - Failed job isolation for debugging
 *
 * @package Glueful\Database\Migrations
 */
class CreateQueueSystemTables implements MigrationInterface
{
    /**
     * Execute the migration
     *
     * Creates essential queue system tables with:
     * - Optimized indexes for job processing
     * - Proper data types for performance
     * - Minimal schema for fast operations
     *
     * Tables created:
     * - queue_jobs: Core job queue with priority and delay support
     * - queue_failed_jobs: Failed job tracking for debugging and retry
     * - queue_batches: Batch processing for grouped operations
     *
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function up(SchemaBuilderInterface $schema): void
    {
        // Create Queue Jobs Table with auto-execute
        $schema->createTable('queue_jobs', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12)->unique();
            $table->string('queue', 255)->default('default');
            $table->text('payload');
            $table->integer('attempts')->default(0);
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('available_at');
            $table->integer('priority')->default(0);
            $table->string('batch_uuid', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->index('queue');
            $table->index('reserved_at');
            $table->index('available_at');
            $table->index('priority');
            $table->index('batch_uuid');
            $table->index(['queue', 'reserved_at'], 'idx_queue_reserved');
            $table->index(['queue', 'available_at'], 'idx_queue_available');
            $table->index(['priority', 'available_at'], 'idx_priority_available');
        });

        // Create Failed Jobs Table with auto-execute
        $schema->createTable('queue_failed_jobs', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12)->unique();
            $table->string('connection', 255);
            $table->string('queue', 255);
            $table->text('payload');
            $table->text('exception');
            $table->string('batch_uuid', 12)->nullable();
            $table->timestamp('failed_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->index('connection');
            $table->index('queue');
            $table->index('batch_uuid');
            $table->index('failed_at');
            $table->index(['connection', 'queue'], 'idx_failed_connection_queue');
        });

        // Create Queue Batches Table with auto-execute
        $schema->createTable('queue_batches', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12)->unique();
            $table->string('name', 255);
            $table->integer('total_jobs')->default(0);
            $table->integer('pending_jobs')->default(0);
            $table->integer('processed_jobs')->default(0);
            $table->integer('failed_jobs')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('options')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            // Add indexes
            $table->index('name');
            $table->index('cancelled_at');
            $table->index('finished_at');
            $table->index('created_at');
            $table->index(['pending_jobs', 'created_at'], 'idx_batch_pending');
        });
    }

    /**
     * Reverse the migration
     *
     * Removes all queue system tables in correct order:
     * - No foreign key constraints to worry about
     * - Safe to drop in any order
     * - Cleans up all queue data
     *
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('queue_batches');
        $schema->dropTableIfExists('queue_failed_jobs');
        $schema->dropTableIfExists('queue_jobs');
    }

    /**
     * Get migration description
     *
     * @return string Migration description
     */
    public function getDescription(): string
    {
        return 'Creates essential queue system tables for async job processing, ' .
               'failed job tracking, and batch operations';
    }
}
