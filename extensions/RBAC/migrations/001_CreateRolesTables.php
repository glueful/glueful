<?php

namespace Glueful\Extensions\RBAC\Database\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * RBAC Roles Tables Migration
 *
 * Creates RBAC role management tables:
 * - rbac_roles: Role definitions with hierarchy
 * - rbac_user_roles: User-role assignments with scope
 *
 * Features:
 * - Hierarchical role structure
 * - Scoped role assignments
 * - Temporal permissions (expiry)
 * - Audit trail support
 * - Soft deletes
 */
class CreateRolesTables implements MigrationInterface
{
    /**
     * Execute the migration
     */
    public function up(SchemaBuilderInterface $schema): void
    {
        // Create Roles Table
        $schema->createTable('roles', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('name', 100);
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('parent_uuid', 12)->nullable();
            $table->integer('level')->default(0);
            $table->boolean('is_system')->default(false);
            $table->json('metadata')->nullable();
            $table->enum('status', ['active', 'inactive'], 'active');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('deleted_at')->nullable();

            // Add indexes
            $table->unique('uuid');
            $table->unique('name');
            $table->unique('slug');
            $table->index('parent_uuid');
            $table->index('status');
            $table->index('level');

            // Add self-referencing foreign key
            $table->foreign('parent_uuid')
                ->references('uuid')
                ->on('roles')
                ->nullOnDelete();
        });

        // Create User Roles Table
        $schema->createTable('user_roles', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('user_uuid', 12);
            $table->string('role_uuid', 12);
            $table->json('scope')->nullable();
            $table->string('granted_by', 12)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            // Add indexes
            $table->unique('uuid');
            $table->index('user_uuid');
            $table->index('role_uuid');
            $table->index('expires_at');
            $table->index('granted_by');

            // Add foreign keys
            $table->foreign('user_uuid')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('role_uuid')
                ->references('uuid')
                ->on('roles')
                ->cascadeOnDelete();

            $table->foreign('granted_by')
                ->references('uuid')
                ->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migration
     */
    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('user_roles');
        $schema->dropTableIfExists('roles');
    }

    /**
     * Get migration description
     */
    public function getDescription(): string
    {
        return 'Create RBAC roles and user roles tables with hierarchical support';
    }
}
