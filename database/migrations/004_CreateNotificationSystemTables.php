<?php

namespace Glueful\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

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
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function up(SchemaBuilderInterface $schema): void
    {
        // Create Notifications Table
        $schema->createTable('notifications', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('type', 100);
            $table->string('subject', 255);
            $table->json('data')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->string('notifiable_type', 100);
            $table->string('notifiable_id', 255);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            // Add indexes
            $table->unique('uuid');
            $table->index('notifiable_type');
            $table->index('notifiable_id');
            $table->index('type');
            $table->index('read_at');
            $table->index('scheduled_at');
        });

        // Create Notification Preferences Table
        $schema->createTable('notification_preferences', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('notifiable_type', 100);
            $table->string('notifiable_id', 255);
            $table->string('notification_type', 100);
            $table->json('channels')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            // Add indexes
            $table->unique('uuid');
            $table->index('notifiable_type');
            $table->index('notifiable_id');
            $table->index('notification_type');
            $table->unique(
                ['notifiable_type', 'notifiable_id', 'notification_type'],
                'unique_notification_pref'
            );
        });

        // Create Notification Templates Table
        $schema->createTable('notification_templates', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('name', 255);
            $table->string('notification_type', 100);
            $table->string('channel', 100);
            $table->text('content');
            $table->json('parameters')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();

            // Add indexes
            $table->unique('uuid');
            $table->index('notification_type');
            $table->index('channel');
            $table->unique(['notification_type', 'channel', 'name'], 'unique_notification_template');
        });
    }

    /**
     * Reverse the migration
     *
     * Removes all created notification system tables in correct order:
     * - Templates first
     * - Preferences next
     * - Notifications last
     *
     * @param SchemaBuilderInterface $schema Database schema manager
     */
    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('notification_templates');
        $schema->dropTableIfExists('notification_preferences');
        $schema->dropTableIfExists('notifications');
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
