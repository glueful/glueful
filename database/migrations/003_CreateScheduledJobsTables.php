<?php

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

/**
 * Create Scheduled Jobs Tables Migration
 * 
 * Creates tables for the job scheduling system:
 * - scheduled_jobs: Stores job definitions and schedules
 * - job_executions: Tracks job execution history and results
 * 
 * This migration enables a robust job scheduling system with:
 * - Cron-based scheduling
 * - Execution history and results tracking
 * - Parameterized jobs
 * - Job status management
 * 
 * @package Glueful\Database\Migrations
 */
class CreateScheduledJobsTables implements MigrationInterface
{
    /**
     * Execute the migration
     * 
     * Creates the scheduled_jobs and job_executions tables with:
     * - UUID primary keys
     * - Foreign key relationships
     * - JSON parameter storage
     * - Proper indexing
     * - Status tracking fields
     * 
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        // Create Scheduled Jobs Table
        $schema->createTable('scheduled_jobs', [
            'id' => 'VARCHAR(36) PRIMARY KEY',
            'uuid' => 'CHAR(12) NOT NULL',
            'name' => 'VARCHAR(255) NOT NULL',
            'schedule' => 'VARCHAR(100) NOT NULL',
            'handler_class' => 'VARCHAR(255) NOT NULL',
            'parameters' => 'JSON',
            'is_enabled' => 'TINYINT(1) DEFAULT 1',
            'last_run' => 'DATETIME NULL',
            'next_run' => 'DATETIME NULL',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME NULL'
        ])->addIndex([
            ['type' => 'INDEX', 'column' => 'name', 'table' => 'scheduled_jobs'],
            ['type' => 'INDEX', 'column' => 'next_run', 'table' => 'scheduled_jobs'],
            ['type' => 'INDEX', 'column' => 'is_enabled', 'table' => 'scheduled_jobs']
        ]);

        // Create Job Executions Table
        $schema->createTable('job_executions', [
            'id' => 'VARCHAR(36) PRIMARY KEY',
            'uuid' => 'CHAR(12) NOT NULL',
            'job_uuid' => 'CHAR(12) NOT NULL',
            'status' => "ENUM('success', 'failure', 'running') NOT NULL",
            'started_at' => 'DATETIME NOT NULL',
            'completed_at' => 'DATETIME NULL',
            'result' => 'TEXT NULL',
            'error_message' => 'TEXT NULL',
            'execution_time' => 'FLOAT NULL',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'INDEX', 'column' => 'job_uuid', 'table' => 'job_executions'],
            ['type' => 'INDEX', 'column' => 'status', 'table' => 'job_executions'],
            ['type' => 'INDEX', 'column' => 'started_at', 'table' => 'job_executions'],
            ['type' => 'FOREIGN KEY', 'column' => 'job_uuid', 'table' => 'job_executions', 'references' => 'uuid', 'on' => 'scheduled_jobs', 'onDelete' => 'CASCADE']
        ]);
    }

    /**
     * Reverse the migration
     * 
     * Drops tables in correct order to respect foreign key constraints
     * 
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        // Drop in reverse order to respect foreign key constraints
        $schema->dropTable('job_executions');
        $schema->dropTable('scheduled_jobs');
    }

    /**
     * Get migration description
     * 
     * @return string Migration description
     */
    public function getDescription(): string
    {
        return 'Creates scheduled jobs and job executions tables for the scheduling system';
    }
}
