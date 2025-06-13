<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

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
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        // Create Queue Jobs Table
        $schema->createTable('queue_jobs', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'queue' => 'VARCHAR(255) NOT NULL DEFAULT \'default\'',
            'payload' => 'TEXT NOT NULL',
            'attempts' => 'INT DEFAULT 0',
            'reserved_at' => 'TIMESTAMP NULL',
            'available_at' => 'TIMESTAMP NOT NULL',
            'priority' => 'INT DEFAULT 0',
            'batch_uuid' => 'CHAR(12) NULL',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'queue'],
            ['type' => 'INDEX', 'column' => 'reserved_at'],
            ['type' => 'INDEX', 'column' => 'available_at'],
            ['type' => 'INDEX', 'column' => 'priority'],
            ['type' => 'INDEX', 'column' => 'batch_uuid'],
            ['type' => 'INDEX', 'column' => ['queue', 'reserved_at'], 'name' => 'idx_queue_reserved'],
            ['type' => 'INDEX', 'column' => ['queue', 'available_at'], 'name' => 'idx_queue_available'],
            ['type' => 'INDEX', 'column' => ['priority', 'available_at'], 'name' => 'idx_priority_available']
        ]);

        // Create Failed Jobs Table
        $schema->createTable('queue_failed_jobs', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'connection' => 'VARCHAR(255) NOT NULL',
            'queue' => 'VARCHAR(255) NOT NULL',
            'payload' => 'TEXT NOT NULL',
            'exception' => 'TEXT NOT NULL',
            'failed_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'connection'],
            ['type' => 'INDEX', 'column' => 'queue'],
            ['type' => 'INDEX', 'column' => 'failed_at'],
            ['type' => 'INDEX', 'column' => ['connection', 'queue'], 'name' => 'idx_failed_connection_queue']
        ]);

        // Create Queue Batches Table
        $schema->createTable('queue_batches', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'name' => 'VARCHAR(255) NOT NULL',
            'total_jobs' => 'INT NOT NULL DEFAULT 0',
            'pending_jobs' => 'INT NOT NULL DEFAULT 0',
            'processed_jobs' => 'INT NOT NULL DEFAULT 0',
            'failed_jobs' => 'INT NOT NULL DEFAULT 0',
            'cancelled_at' => 'TIMESTAMP NULL',
            'finished_at' => 'TIMESTAMP NULL',
            'options' => 'JSON NULL',
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'name'],
            ['type' => 'INDEX', 'column' => 'cancelled_at'],
            ['type' => 'INDEX', 'column' => 'finished_at'],
            ['type' => 'INDEX', 'column' => 'created_at'],
            ['type' => 'INDEX', 'column' => ['pending_jobs', 'created_at'], 'name' => 'idx_batch_pending']
        ]);
    }

    /**
     * Reverse the migration
     *
     * Removes all queue system tables in correct order:
     * - No foreign key constraints to worry about
     * - Safe to drop in any order
     * - Cleans up all queue data
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        $schema->dropTable('queue_batches');
        $schema->dropTable('queue_failed_jobs');
        $schema->dropTable('queue_jobs');
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
