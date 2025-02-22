<?php

use Glueful\App\Migrations\MigrationInterface;
use Glueful\Api\Schemas\SchemaManager;

class CreateInitialSchema implements MigrationInterface
{
    public function up(SchemaManager $schema): void
    {
        // Create Users Table
        $schema->createTable('users', [
            'id' => 'BIGINT AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'username' => 'VARCHAR(255) NOT NULL',
            'email' => 'VARCHAR(255) NOT NULL',
            'password' => 'VARCHAR(100)',
            'status' => "VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted'))",
            'user_agent' => 'VARCHAR(512)',
            'ip_address' => 'VARCHAR(40)',
            'x_forwarded_for_ip_address' => 'VARCHAR(40)',
            'last_login_date' => 'TIMESTAMP',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        ], [
            ['type' => 'PRIMARY KEY', 'column' => 'id'],
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'UNIQUE', 'column' => 'username'],
            ['type' => 'UNIQUE', 'column' => 'email'],
            ['type' => 'INDEX', 'column' => 'email'],
            ['type' => 'INDEX', 'column' => 'username'],
            ['type' => 'INDEX', 'column' => 'uuid']
        ]);

        // Create Roles Table
        $schema->createTable('roles', [
            'id' => 'BIGINT AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'name' => 'VARCHAR(255) NOT NULL',
            'description' => 'TEXT NOT NULL',
            'status' => "VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted'))",
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        ], [
            ['type' => 'PRIMARY KEY', 'column' => 'id'],
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'name'],
            ['type' => 'INDEX', 'column' => 'uuid']
        ]);

        // Create Permissions Table
        $schema->createTable('permissions', [
            'id' => 'BIGINT AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'role_uuid' => 'CHAR(12) NOT NULL',
            'model' => 'VARCHAR(255) NOT NULL',
            'permissions' => 'VARCHAR(10) NOT NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ], [
            ['type' => 'PRIMARY KEY', 'column' => 'id'],
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'role_uuid'],
            ['type' => 'INDEX', 'column' => 'uuid']
        ], [
            [
                'column' => 'role_uuid',
                'referenceTable' => 'roles',
                'referenceColumn' => 'uuid',
                'onDelete' => 'CASCADE'
            ]
        ]);

        // Create Blobs Table
        $schema->createTable('blobs', [
            'id' => 'BIGINT AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'name' => 'VARCHAR(255) NOT NULL',
            'description' => 'TEXT',
            'mime_type' => 'VARCHAR(127) NOT NULL',
            'size' => 'BIGINT NOT NULL',
            'url' => 'VARCHAR(2048) NOT NULL',
            'status' => "VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted'))",
            'created_by' => 'CHAR(12) NOT NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP'
        ], [
            ['type' => 'PRIMARY KEY', 'column' => 'id'],
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'created_by'],
            ['type' => 'INDEX', 'column' => 'uuid']
        ], [
            [
                'column' => 'created_by',
                'referenceTable' => 'users',
                'referenceColumn' => 'uuid'
            ]
        ]);

        // Create Profiles Table
        $schema->createTable('profiles', [
            'id' => 'BIGINT AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'user_uuid' => 'CHAR(12) NOT NULL',
            'first_name' => 'VARCHAR(100) DEFAULT NULL',
            'last_name' => 'VARCHAR(100) DEFAULT NULL',
            'photo_uuid' => 'CHAR(12) DEFAULT NULL',
            'photo_url' => 'VARCHAR(255) DEFAULT NULL',
            'status' => "VARCHAR(20) NOT NULL CHECK (status IN ('active', 'inactive', 'deleted'))",
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ], [
            ['type' => 'PRIMARY KEY', 'column' => 'id'],
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'user_uuid'],
            ['type' => 'INDEX', 'column' => 'photo_uuid']
        ], [
            [
                'column' => 'user_uuid',
                'referenceTable' => 'users',
                'referenceColumn' => 'uuid',
                'onDelete' => 'CASCADE'
            ],
            [
                'column' => 'photo_uuid',
                'referenceTable' => 'blobs',
                'referenceColumn' => 'uuid',
                'onDelete' => 'SET NULL'
            ]
        ]);


        // Create User Roles Lookup Table
        $schema->createTable('user_roles_lookup', [
            'id' => 'BIGINT AUTO_INCREMENT',
            'user_uuid' => 'CHAR(12) NOT NULL',
            'role_uuid' => 'CHAR(12) NOT NULL'
        ], [
            ['type' => 'PRIMARY KEY', 'column' => 'id'],
            ['type' => 'UNIQUE', 'column' => 'user_uuid'],
            ['type' => 'UNIQUE', 'column' => 'role_uuid'],
            ['type' => 'INDEX', 'column' => 'user_uuid'],
            ['type' => 'INDEX', 'column' => 'role_uuid']
        ], [
            [
                'column' => 'user_uuid',
                'referenceTable' => 'users',
                'referenceColumn' => 'uuid',
                'onDelete' => 'CASCADE'
            ],
            [
                'column' => 'role_uuid',
                'referenceTable' => 'roles',
                'referenceColumn' => 'uuid',
                'onDelete' => 'CASCADE'
            ]
        ]);

        // Create Auth Sessions Table
        $schema->createTable('auth_sessions', [
            'id' => 'BIGINT UNSIGNED AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'user_uuid' => 'CHAR(12) NOT NULL',
            'access_token' => 'VARCHAR(255) NOT NULL',
            'refresh_token' => 'VARCHAR(255) NULL',
            'token_fingerprint' => 'BINARY(32) NOT NULL',
            'ip_address' => 'VARCHAR(45) NULL',
            'user_agent' => 'TEXT NULL',
            'last_token_refresh' => 'TIMESTAMP NULL',
            'access_expires_at' => 'TIMESTAMP NOT NULL',
            'refresh_expires_at' => 'TIMESTAMP NOT NULL',
            'status' => "ENUM('active', 'revoked') DEFAULT 'active'",
            'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ], [
            ['type' => 'PRIMARY KEY', 'column' => 'id'],
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'user_uuid'],
            ['type' => 'INDEX', 'column' => 'status'],
            ['type' => 'INDEX', 'column' => 'access_token'],
            ['type' => 'INDEX', 'column' => 'refresh_token'],
            ['type' => 'INDEX', 'column' => 'token_fingerprint'],
            ['type' => 'INDEX', 'column' => 'uuid']
        ], [
            [
                'column' => 'user_uuid',
                'referenceTable' => 'users',
                'referenceColumn' => 'uuid'
            ]
        ]);

        // Create App Logs Table
        $schema->createTable('app_logs', [
            'id' => 'BIGINT UNSIGNED AUTO_INCREMENT',
            'uuid' => 'CHAR(12) NOT NULL',
            'level' => "ENUM('INFO', 'WARNING', 'ERROR') NOT NULL",
            'message' => 'TEXT NOT NULL',
            'context' => 'JSON NULL',
            'exec_time' => 'FLOAT NULL',  // Execution time for querying
            'channel' => 'VARCHAR(255) NOT NULL',
            'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        ], [
            ['type' => 'PRIMARY KEY', 'column' => 'id'],
            ['type' => 'UNIQUE', 'column' => 'uuid'],
            ['type' => 'INDEX', 'column' => 'level'],
            ['type' => 'INDEX', 'column' => 'channel'],
            ['type' => 'INDEX', 'column' => 'created_at']
        ]);
    }

    public function down(SchemaManager $schema): void
    {
        $schema->dropTable('app_logs');
        $schema->dropTable('auth_sessions');
        $schema->dropTable('user_roles_lookup');
        $schema->dropTable('blobs');
        $schema->dropTable('profiles');
        $schema->dropTable('permissions');
        $schema->dropTable('roles');
        $schema->dropTable('users');
    }

    public function getDescription(): string
    {
        return 'Creates initial database schema including users, roles, and permissions tables';
    }
}
