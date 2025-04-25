<?php
declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

/**
 * Notification System Tables Migration
 * 
 * Creates tables required for the hybrid notification system:
 * - Core notification storage and tracking
 * - User notification preferences
 * - Notification templates for different channels
 * 
 * Database Design:
 * - Follows extension-based architecture
 * - Implements proper indexing for performance
 * - Uses unique constraints where appropriate
 * - Supports read/unread status tracking
 * - Handles scheduled notifications
 * 
 * Features:
 * - Channel-agnostic core system
 * - Flexible preferences per user and notification type
 * - Template-based notification formatting
 * - Support for multiple notification channels
 * 
 * @package Glueful\Database\Migrations
 */
class CreateNotificationSystemTables implements MigrationInterface
{
    /**
     * Execute the migration
     * 
     * Creates all required notification system tables with:
     * - Primary keys and indexes
     * - Notification type tracking
     * - User preference storage
     * - Template management
     * - Notification status tracking
     * 
     * Tables created:
     * - notifications: Core notification storage
     * - notification_preferences: User channel preferences
     * - notification_templates: Templates for different channels
     * 
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        // Create Notifications Table
        $schema->createTable('notifications', [
            'id' => 'VARCHAR(255) PRIMARY KEY',
            'type' => 'VARCHAR(100) NOT NULL',
            'subject' => 'VARCHAR(255) NOT NULL',
            'data' => 'JSON NULL',
            'priority' => "VARCHAR(20) DEFAULT 'normal'",
            'notifiable_type' => 'VARCHAR(100) NOT NULL',
            'notifiable_id' => 'VARCHAR(255) NOT NULL',
            'read_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'scheduled_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'sent_at' => 'TIMESTAMP NULL DEFAULT NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'INDEX', 'column' => 'notifiable_type', 'table' => 'notifications'],
            ['type' => 'INDEX', 'column' => 'notifiable_id', 'table' => 'notifications'],
            ['type' => 'INDEX', 'column' => 'type', 'table' => 'notifications'],
            ['type' => 'INDEX', 'column' => 'read_at', 'table' => 'notifications'],
            ['type' => 'INDEX', 'column' => 'scheduled_at', 'table' => 'notifications']
        ]);

        // Create Notification Preferences Table
        $schema->createTable('notification_preferences', [
            'id' => 'VARCHAR(255) PRIMARY KEY',
            'notifiable_type' => 'VARCHAR(100) NOT NULL',
            'notifiable_id' => 'VARCHAR(255) NOT NULL',
            'notification_type' => 'VARCHAR(100) NOT NULL',
            'channels' => 'JSON NULL',
            'enabled' => 'BOOLEAN DEFAULT 1',
            'settings' => 'JSON NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'INDEX', 'column' => 'notifiable_type', 'table' => 'notification_preferences'],
            ['type' => 'INDEX', 'column' => 'notifiable_id', 'table' => 'notification_preferences'],
            ['type' => 'INDEX', 'column' => 'notification_type', 'table' => 'notification_preferences'],
            ['type' => 'UNIQUE', 'column' => ['notifiable_type', 'notifiable_id', 'notification_type'], 
             'name' => 'unique_notification_pref', 'table' => 'notification_preferences']
        ]);
        
        // Create Notification Templates Table
        $schema->createTable('notification_templates', [
            'id' => 'VARCHAR(255) PRIMARY KEY',
            'name' => 'VARCHAR(255) NOT NULL',
            'notification_type' => 'VARCHAR(100) NOT NULL',
            'channel' => 'VARCHAR(100) NOT NULL',
            'content' => 'TEXT NOT NULL',
            'parameters' => 'JSON NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ])->addIndex([
            ['type' => 'INDEX', 'column' => 'notification_type', 'table' => 'notification_templates'],
            ['type' => 'INDEX', 'column' => 'channel', 'table' => 'notification_templates'],
            ['type' => 'UNIQUE', 'column' => ['notification_type', 'channel', 'name'], 
             'name' => 'unique_notification_template', 'table' => 'notification_templates']
        ]);
    }

    /**
     * Reverse the migration
     * 
     * Removes all created notification system tables in correct order:
     * - Templates first
     * - Preferences next
     * - Notifications last
     * 
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        $schema->dropTable('notification_templates');
        $schema->dropTable('notification_preferences');
        $schema->dropTable('notifications');
    }

    /**
     * Get migration description
     * 
     * Provides human-readable description of the migration
     * 
     * @return string Migration description
     */
    public function getDescription(): string
    {
        return 'Creates notification system tables for the hybrid extension-based notification architecture';
    }
}