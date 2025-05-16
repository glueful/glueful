<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

/**
 * Create audit logging tables for enterprise security features
 *
 * This migration creates:
 * 1. audit_logs - Main table for storing tamper-evident audit events
 * 2. audit_entities - Table for tracking entities referenced in audit logs
 *
 * Security Features:
 * - Tamper-evident audit trails
 * - Entity tracking
 * - Immutable records
 * - Retention policy support
 *
 * Migration for v0.26.0 Enterprise Security milestone
 *
 * @package Glueful\Database\Migrations
 */
class CreateAuditLogTables implements MigrationInterface
{
    /**
     * Execute the migration
     *
     * Creates audit logging tables with:
     * - Primary and foreign keys
     * - Indexes for efficient querying
     * - Timestamps and tracking fields
     * - Security and integrity features
     *
     * Tables created:
     * - audit_logs: Main audit trail with tamper-evident design
     * - audit_entities: Entity reference tracking and metadata
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        // Create Audit Logs Table
        $schema->createTable('audit_logs', [
            'id' => 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'category' => 'VARCHAR(50) NOT NULL',
            'action' => 'VARCHAR(100) NOT NULL',
            'severity' => 'VARCHAR(20) NOT NULL',
            'actor_uuid' => 'CHAR(12) NULL',
            'target_uuid' => 'CHAR(12) NULL',
            'target_type' => 'VARCHAR(50) NULL',
            'timestamp' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'related_event_uuid' => 'CHAR(12) NULL',
            'session_uuid' => 'CHAR(12) NULL',
            'ip_address' => 'VARCHAR(45) NULL',
            'user_agent' => 'TEXT NULL',
            'request_uri' => 'TEXT NULL',
            'request_method' => 'VARCHAR(10) NULL',
            'details' => 'JSON NULL',
            'integrity_hash' => 'VARCHAR(64) NOT NULL',
            'immutable' => 'BOOLEAN NOT NULL DEFAULT TRUE',
            'retention_date' => 'TIMESTAMP NULL'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'category'],
            ['type' => 'INDEX', 'column' => 'action'],
            ['type' => 'INDEX', 'column' => 'severity'],
            ['type' => 'INDEX', 'column' => 'actor_uuid'],
            ['type' => 'INDEX', 'column' => 'target_uuid'],
            ['type' => 'INDEX', 'column' => 'target_type'],
            ['type' => 'INDEX', 'column' => 'timestamp'],
            ['type' => 'INDEX', 'column' => 'related_event_uuid'],
            ['type' => 'INDEX', 'column' => 'session_uuid'],
            ['type' => 'INDEX', 'column' => 'integrity_hash'],
            ['type' => 'INDEX', 'column' => 'retention_date'],
            ['type' => 'INDEX', 'columns' => ['category', 'timestamp']],
            ['type' => 'INDEX', 'columns' => ['actor_uuid', 'timestamp']],
            ['type' => 'INDEX', 'columns' => ['target_uuid', 'target_type']],
            ['type' => 'INDEX', 'columns' => ['category', 'action', 'severity']]
        ])->addForeignKey([
            [
                'column' => 'actor_uuid',
                'references' => 'uuid',
                'on' => 'users',
                'onDelete' => 'SET NULL'
            ]
        ]);

        // Create Audit Entities Table
        $schema->createTable('audit_entities', [
            'id' => 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'entity_uuid' => 'CHAR(12) NOT NULL',
            'entity_type' => 'VARCHAR(50) NOT NULL',
            'entity_name' => 'VARCHAR(255) NOT NULL',
            'entity_metadata' => 'JSON NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'entity_uuid'],
            ['type' => 'INDEX', 'column' => 'entity_type'],
            ['type' => 'INDEX', 'column' => 'entity_name'],
            ['type' => 'INDEX', 'columns' => ['entity_type', 'entity_name']]
        ]);
    }

    /**
     * Reverse the migration
     *
     * Removes all created audit tables in correct order:
     * - Respects foreign key constraints
     * - Handles dependent tables first
     * - Cleans up completely
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        $schema->dropTable('audit_entities');
        $schema->dropTable('audit_logs');
    }

    /**
     * Get migration description
     *
     * Provides human-readable description of:
     * - Migration purpose
     * - Major changes
     * - System impacts
     *
     * @return string Migration description
     */
    public function getDescription(): string
    {
        return 'Creates audit logging tables for enterprise security features and tamper-evident records';
    }
}
