<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Create Audit Logs Table Migration
 *
 * Creates the audit_logs table for comprehensive system activity tracking.
 * This table stores security events, data changes, user actions, and system
 * events for compliance, debugging, and security monitoring.
 *
 * Table structure:
 * - id: Primary key auto-increment
 * - uuid: Unique identifier for each log entry
 * - user_id: User who performed the action (nullable for system events)
 * - action: Action type (create, update, delete, login, logout, etc.)
 * - category: Log category (general, admin, security, data, system)
 * - severity: Log severity level (critical, error, warning, info, debug)
 * - status: Operation status (success, failed, pending)
 * - entity_type: Type of entity affected (users, sessions, schema, etc.)
 * - entity_id: Unique identifier of the affected entity
 * - old_values: JSON of previous state (for updates/deletes)
 * - new_values: JSON of new state (for creates/updates)
 * - ip_address: Client IP address
 * - user_agent: Client user agent string
 * - context: Additional context data as JSON
 * - metadata: Additional structured data as JSON
 * - session_id: Session identifier for correlating actions
 * - request_id: Request identifier for correlating with request logs
 * - parent_audit_id: Reference to parent audit entry for linked operations
 * - source: Source of the action (api, cli, cron, system)
 * - duration_ms: Operation duration in milliseconds
 * - created_at: Timestamp when the event occurred
 * - expires_at: Timestamp for automatic log purging (GDPR compliance)
 *
 * @package Glueful\Database\Migrations
 */
class CreateAuditLogsTable implements MigrationInterface
{
    /**
     * Execute the migration
     *
     * Creates the audit_logs table with:
     * - Primary key on id for efficient lookups
     * - Unique key on uuid for external references
     * - Indexes for common query patterns
     * - Foreign key to users table for user_id
     * - JSON columns for flexible data storage
     *
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function up(SchemaBuilderInterface $schema): void
    {
        // Create Audit Logs Table with auto-execute
        $schema->createTable('audit_logs', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('user_id', 12)->nullable();
            $table->string('action', 50);
            $table->string('category', 50)->default('general');
            $table->string('severity', 20)->default('info');
            $table->string('status', 20)->default('success');
            $table->string('entity_type', 100);
            $table->string('entity_id', 100);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable();
            $table->json('metadata')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->string('request_id', 50)->nullable();
            $table->string('parent_audit_id', 12)->nullable();
            $table->string('source', 50)->default('api');
            $table->integer('duration_ms')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('expires_at')->nullable();

            // Add indexes
            $table->unique('uuid');
            $table->index('user_id');
            $table->index('action');
            $table->index('category');
            $table->index('severity');
            $table->index('status');
            $table->index('entity_type');
            $table->index('entity_id');
            $table->index('session_id');
            $table->index('request_id');
            $table->index('source');
            $table->index('created_at');
            $table->index('expires_at');
            $table->index(['user_id', 'action']);
            $table->index(['category', 'action']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['category', 'severity']);

            // Add foreign keys
            $table->foreign('user_id')
                ->references('uuid')
                ->on('users')
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->foreign('parent_audit_id')
                ->references('uuid')
                ->on('audit_logs')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migration
     *
     * Drops the audit_logs table.
     *
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('audit_logs');
    }

    /**
     * Get migration description
     *
     * @return string Migration description
     */
    public function getDescription(): string
    {
        return 'Creates audit_logs table for comprehensive system activity tracking and compliance';
    }
}
