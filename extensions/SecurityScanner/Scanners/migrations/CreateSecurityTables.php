<?php

namespace Glueful\Extensions\SecurityScanner\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

/**
 * Security Tables Migration
 *
 * Creates database tables for the Security Scanner extension:
 * - Security scans tracking
 * - Security vulnerabilities
 * - Security reports and remediation
 *
 * Database Design:
 * - Implements proper indexing
 * - Uses foreign key constraints
 * - Tracks vulnerability lifecycle
 * - Stores detailed security metadata
 *
 * @package Glueful\Extensions\SecurityScanner\Database\Migrations
 */
class CreateSecurityTables implements MigrationInterface
{
    /**
     * Execute the migration
     *
     * Creates security-related database tables with:
     * - Primary and foreign keys
     * - Indexes for optimization
     * - Data integrity constraints
     * - Timestamp tracking
     *
     * Tables created:
     * - security_scans: Metadata about security scans
     * - security_vulnerabilities: Detailed vulnerability information
     * - security_reports: Aggregated security reports
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        // Create Security Scans Table
        $schema->createTable('security_scans', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'type' => 'VARCHAR(20) NOT NULL',  // code, dependency, api
            'timestamp' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'files_scanned' => 'INT DEFAULT NULL',
            'packages_scanned' => 'INT DEFAULT NULL',
            'endpoints_scanned' => 'INT DEFAULT NULL',
            'tests_performed' => 'INT DEFAULT NULL',
            'vulnerabilities_found' => 'INT NOT NULL DEFAULT 0',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'type'],
            ['type' => 'INDEX', 'column' => 'timestamp']
        ]);

        // Create Security Vulnerabilities Table
        $schema->createTable('security_vulnerabilities', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'scan_uuid' => 'CHAR(12) NOT NULL',
            'file_path' => 'VARCHAR(1024) DEFAULT NULL',
            'package_type' => 'VARCHAR(50) DEFAULT NULL',
            'package_name' => 'VARCHAR(255) DEFAULT NULL',
            'package_version' => 'VARCHAR(50) DEFAULT NULL',
            'endpoint' => 'VARCHAR(1024) DEFAULT NULL',
            'method' => 'VARCHAR(10) DEFAULT NULL',
            'rule_id' => 'VARCHAR(100) DEFAULT NULL',
            'test_id' => 'VARCHAR(100) DEFAULT NULL',
            'vulnerability_id' => 'VARCHAR(100) DEFAULT NULL',
            'title' => 'VARCHAR(255) DEFAULT NULL',
            'description' => 'TEXT NOT NULL',
            'severity' => "VARCHAR(20) NOT NULL CHECK (severity IN ('critical', 'high', 'medium', 'low'))",
            'line' => 'INT DEFAULT NULL',
            'code' => 'TEXT DEFAULT NULL',
            'payload' => 'VARCHAR(1024) DEFAULT NULL',
            'fixed_version' => 'VARCHAR(50) DEFAULT NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'new' " .
                        "CHECK (status IN ('new', 'in_progress', 'fixed', 'ignored'))",
            'comment' => 'TEXT DEFAULT NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'scan_uuid'],
            ['type' => 'INDEX', 'column' => 'severity'],
            ['type' => 'INDEX', 'column' => 'status'],
            ['type' => 'INDEX', 'column' => 'vulnerability_id'],
            ['type' => 'INDEX', 'columns' => ['package_name', 'package_version']]
        ])->addForeignKey([
            [
                'column' => 'scan_uuid',
                'references' => 'uuid',
                'on' => 'security_scans',
                'onDelete' => 'CASCADE'
            ]
        ]);

        // Create Security Reports Table
        $schema->createTable('security_reports', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'timestamp' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'total_vulnerabilities' => 'INT NOT NULL DEFAULT 0',
            'risk_level' => "VARCHAR(20) NOT NULL CHECK (risk_level IN ('critical', 'high', 'medium', 'low'))",
            'critical_count' => 'INT NOT NULL DEFAULT 0',
            'high_count' => 'INT NOT NULL DEFAULT 0',
            'medium_count' => 'INT NOT NULL DEFAULT 0',
            'low_count' => 'INT NOT NULL DEFAULT 0',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'timestamp'],
            ['type' => 'INDEX', 'column' => 'risk_level']
        ]);

        // Create Scan Schedule Table
        $schema->createTable('security_scan_schedules', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'scanner_type' => 'VARCHAR(20) NOT NULL',  // code, dependency, api
            'frequency' => "VARCHAR(20) NOT NULL " .
                "CHECK (frequency IN ('hourly', 'daily', 'weekly', 'monthly', 'custom'))",
            'cron_expression' => 'VARCHAR(100) DEFAULT NULL',
            'last_run' => 'TIMESTAMP NULL',
            'next_run' => 'TIMESTAMP NULL',
            'enabled' => 'BOOLEAN NOT NULL DEFAULT 1',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'scanner_type'],
            ['type' => 'INDEX', 'column' => 'next_run']
        ]);

        // Create Security Remediation Tasks Table
        $schema->createTable('security_remediation_tasks', [
            'id' => 'BIGINT PRIMARY KEY AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'vulnerability_uuid' => 'CHAR(12) NOT NULL',
            'assigned_to' => 'CHAR(12) DEFAULT NULL', // user_uuid
            'priority' => "VARCHAR(20) NOT NULL DEFAULT 'medium' " .
                "CHECK (priority IN ('critical', 'high', 'medium', 'low'))",
            'due_date' => 'TIMESTAMP NULL',
            'status' => "VARCHAR(20) NOT NULL DEFAULT 'pending' " .
                "CHECK (status IN ('pending', 'in_progress', 'completed', 'deferred'))",
            'notes' => 'TEXT DEFAULT NULL',
            'created_by' => 'CHAR(12) DEFAULT NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'vulnerability_uuid'],
            ['type' => 'INDEX', 'column' => 'assigned_to'],
            ['type' => 'INDEX', 'column' => 'status'],
            ['type' => 'INDEX', 'column' => 'priority']
        ])->addForeignKey([
            [
                'column' => 'vulnerability_uuid',
                'references' => 'uuid',
                'on' => 'security_vulnerabilities',
                'onDelete' => 'CASCADE'
            ],
            [
                'column' => 'assigned_to',
                'references' => 'uuid',
                'on' => 'users',
                'onDelete' => 'SET NULL'
            ],
            [
                'column' => 'created_by',
                'references' => 'uuid',
                'on' => 'users',
                'onDelete' => 'SET NULL'
            ]
        ]);
    }

    /**
     * Reverse the migration
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        $schema->dropTable('security_remediation_tasks');
        $schema->dropTable('security_scan_schedules');
        $schema->dropTable('security_reports');
        $schema->dropTable('security_vulnerabilities');
        $schema->dropTable('security_scans');
    }

    /**
     * Get the migration description
     *
     * @return string The migration description
     */
    public function getDescription(): string
    {
        return 'Creates security tables for vulnerability tracking and management';
    }
}
