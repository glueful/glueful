<?php

namespace Glueful\Extensions\RBAC\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * RBAC Permissions Tables Migration
 *
 * Creates RBAC permission management tables:
 * - rbac_permissions: Permission definitions
 * - rbac_role_permissions: Role-permission assignments
 * - rbac_user_permissions: Direct user permissions
 * - rbac_permission_audit: Permission audit trail
 *
 * Features:
 * - Granular permission definitions
 * - Resource-level filtering
 * - Temporal constraints
 * - Complete audit trail
 * - Permission inheritance
 */
class CreatePermissionsTables implements MigrationInterface
{
    /**
     * Execute the migration
     */
    public function up(SchemaBuilderInterface $schema): void
    {
        // Create Permissions Table
        $schema->createTable('permissions', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('category', 50)->nullable();
            $table->string('resource_type', 100)->nullable();
            $table->boolean('is_system')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->unique('uuid');
            $table->unique('name');
            $table->unique('slug');
            $table->index('category');
            $table->index('resource_type');
        });

        // Create Role Permissions Table
        $schema->createTable('role_permissions', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('role_uuid', 12);
            $table->string('permission_uuid', 12);
            $table->json('resource_filter')->nullable();
            $table->json('constraints')->nullable();
            $table->string('granted_by', 12)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->unique('uuid');
            $table->index('role_uuid');
            $table->index('permission_uuid');
            $table->index('expires_at');
            $table->index('granted_by');

            // Add foreign keys
            $table->foreign('role_uuid')
                ->references('uuid')
                ->on('roles')
                ->cascadeOnDelete();

            $table->foreign('permission_uuid')
                ->references('uuid')
                ->on('permissions')
                ->cascadeOnDelete();

            $table->foreign('granted_by')
                ->references('uuid')
                ->on('users')
                ->nullOnDelete();
        });

        // Create User Permissions Table
        $schema->createTable('user_permissions', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('user_uuid', 12);
            $table->string('permission_uuid', 12);
            $table->json('resource_filter')->nullable();
            $table->json('constraints')->nullable();
            $table->string('granted_by', 12)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->unique('uuid');
            $table->index('user_uuid');
            $table->index('permission_uuid');
            $table->index('expires_at');
            $table->index('granted_by');

            // Add foreign keys
            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('permission_uuid')
                ->references('uuid')
                ->on('permissions')
                ->cascadeOnDelete();

            $table->foreign('granted_by')
                ->references('uuid')
                ->on('users')
                ->nullOnDelete();
        });

        // Create Permission Audit Table
        $schema->createTable('permission_audit', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->enum('action', ['GRANTED', 'REVOKED', 'MODIFIED', 'EXPIRED']);
            $table->enum('subject_type', ['user', 'role']);
            $table->string('subject_uuid', 12);
            $table->string('permission_uuid', 12);
            $table->string('target_uuid', 12)->nullable();
            $table->json('old_data')->nullable();
            $table->json('new_data')->nullable();
            $table->text('reason')->nullable();
            $table->string('performed_by', 12)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->unique('uuid');
            $table->index('subject_type');
            $table->index('subject_uuid');
            $table->index('permission_uuid');
            $table->index('target_uuid');
            $table->index('performed_by');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migration
     */
    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('permission_audit');
        $schema->dropTableIfExists('user_permissions');
        $schema->dropTableIfExists('role_permissions');
        $schema->dropTableIfExists('permissions');
    }

    /**
     * Get migration description
     */
    public function getDescription(): string
    {
        return 'Create RBAC permissions, role-permissions, user-permissions, and audit tables';
    }
}
