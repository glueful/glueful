<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

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
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function up(SchemaBuilderInterface $schema): void
    {
        // Create Scheduled Jobs Table
        $schema->createTable('scheduled_jobs', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('name', 255);
            $table->string('schedule', 100);
            $table->string('handler_class', 255);
            $table->json('parameters')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_run')->nullable();
            $table->timestamp('next_run')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            // Add indexes
            $table->unique('uuid');
            $table->index('name');
            $table->index('next_run');
            $table->index('is_enabled');
        });

        // Create Job Executions Table
        $schema->createTable('job_executions', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('job_uuid', 12);
            $table->enum('status', ['success', 'failure', 'running']);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('result')->nullable();
            $table->text('error_message')->nullable();
            $table->float('execution_time')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->unique('uuid');
            $table->index('job_uuid');
            $table->index('status');
            $table->index('started_at');

            // Add foreign key
            $table->foreign('job_uuid')
                ->references('uuid')
                ->on('scheduled_jobs')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migration
     *
     * Drops tables in correct order to respect foreign key constraints
     *
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function down(SchemaBuilderInterface $schema): void
    {
        // Drop in reverse order to respect foreign key constraints
        $schema->dropTableIfExists('job_executions');
        $schema->dropTableIfExists('scheduled_jobs');
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
