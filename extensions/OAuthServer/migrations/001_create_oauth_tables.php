<?php

namespace Glueful\Extensions\OAuthServer\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\SchemaManager;

/**
 * OAuth Server Database Schema Migration
 *
 * Creates tables for the OAuth 2.0 server implementation:
 * - Client registration and management
 * - Access token and refresh token storage
 * - Authorization code storage
 * - Scope definitions and management
 *
 * Database Design:
 * - Follows OAuth 2.0 specification requirements
 * - Implements proper indexing for performance
 * - Uses foreign key constraints for data integrity
 * - Supports token revocation
 * - Handles PKCE authentication flow
 *
 * Security Features:
 * - Client secret hashing
 * - Token storage
 * - Scope-based authorization
 * - Expiration tracking
 *
 * @package Glueful\Extensions\OAuthServer\Migrations
 */
class CreateOAuthTables implements MigrationInterface
{
    /**
     * Execute the migration
     *
     * Creates all required OAuth 2.0 database tables with:
     * - Primary and foreign keys
     * - Indexes for optimization
     * - Data integrity constraints
     * - Timestamp tracking
     *
     * Tables created:
     * - oauth_clients: OAuth client applications
     * - oauth_access_tokens: Access token storage
     * - oauth_refresh_tokens: Refresh token storage
     * - oauth_authorization_codes: Authorization code storage
     * - oauth_scopes: Available authorization scopes
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function up(SchemaManager $schema): void
    {
        // Create oauth_clients table
        $schema->createTable('oauth_clients', [
            'id' => 'VARCHAR(255) PRIMARY KEY',
            'name' => 'VARCHAR(255) NOT NULL',
            'description' => 'TEXT NULL',
            'redirect_uris' => 'TEXT NOT NULL',  // JSON array
            'allowed_grant_types' => 'TEXT NOT NULL',  // JSON array
            'is_confidential' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'secret' => 'VARCHAR(255) NULL',
            'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'created_at' => 'INT NOT NULL',
            'updated_at' => 'INT NOT NULL'
        ])->addIndex([
            ['type' => 'INDEX', 'column' => 'is_default']
        ]);

        // Create oauth_access_tokens table
        $schema->createTable('oauth_access_tokens', [
            'id' => 'VARCHAR(255) PRIMARY KEY',
            'token' => 'VARCHAR(255) NOT NULL',
            'client_id' => 'VARCHAR(255) NOT NULL',
            'user_id' => 'VARCHAR(255) NULL',
            'expires_at' => 'INT NOT NULL',
            'scopes' => 'TEXT NULL',  // JSON array
            'revoked' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'created_at' => 'INT NOT NULL'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'token'],
            ['type' => 'INDEX', 'columns' => ['client_id', 'user_id']],
            ['type' => 'INDEX', 'column' => 'expires_at']
        ])->addForeignKey([
            [
                'column' => 'client_id',
                'references' => 'id',
                'on' => 'oauth_clients',
                'onDelete' => 'CASCADE'
            ]
        ]);

        // Create oauth_refresh_tokens table
        $schema->createTable('oauth_refresh_tokens', [
            'id' => 'VARCHAR(255) PRIMARY KEY',
            'token' => 'VARCHAR(255) NOT NULL',
            'access_token_id' => 'VARCHAR(255) NOT NULL',
            'client_id' => 'VARCHAR(255) NOT NULL',
            'user_id' => 'VARCHAR(255) NULL',
            'expires_at' => 'INT NOT NULL',
            'scopes' => 'TEXT NULL',  // JSON array
            'revoked' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'created_at' => 'INT NOT NULL'
        ])->addIndex([
            ['type' => 'UNIQUE', 'column' => 'token'],
            ['type' => 'INDEX', 'columns' => ['client_id', 'user_id']],
            ['type' => 'INDEX', 'column' => 'expires_at']
        ])->addForeignKey([
            [
                'column' => 'access_token_id',
                'references' => 'id',
                'on' => 'oauth_access_tokens',
                'onDelete' => 'CASCADE'
            ],
            [
                'column' => 'client_id',
                'references' => 'id',
                'on' => 'oauth_clients',
                'onDelete' => 'CASCADE'
            ]
        ]);

        // Create oauth_authorization_codes table
        $schema->createTable('oauth_authorization_codes', [
            'code' => 'VARCHAR(255) PRIMARY KEY',
            'client_id' => 'VARCHAR(255) NOT NULL',
            'user_id' => 'VARCHAR(255) NULL',
            'redirect_uri' => 'VARCHAR(2048) NOT NULL',
            'expires_at' => 'INT NOT NULL',
            'scopes' => 'TEXT NULL',  // JSON array
            'code_challenge' => 'VARCHAR(255) NULL',
            'code_challenge_method' => 'VARCHAR(50) NULL',
            'is_used' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'revoked' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'created_at' => 'INT NOT NULL'
        ])->addIndex([
            ['type' => 'INDEX', 'columns' => ['client_id', 'user_id']],
            ['type' => 'INDEX', 'column' => 'expires_at']
        ])->addForeignKey([
            [
                'column' => 'client_id',
                'references' => 'id',
                'on' => 'oauth_clients',
                'onDelete' => 'CASCADE'
            ]
        ]);

        // Create oauth_scopes table
        $schema->createTable('oauth_scopes', [
            'identifier' => 'VARCHAR(100) PRIMARY KEY',
            'name' => 'VARCHAR(255) NOT NULL',
            'description' => 'TEXT NULL',
            'is_default' => 'TINYINT(1) NOT NULL DEFAULT 0'
        ])->addIndex([
            ['type' => 'INDEX', 'column' => 'is_default']
        ]);

        // Note: Seeding of default scopes and clients is handled in 002_SeedOAuthData.php
    }

    /**
     * Reverse the migration
     *
     * Removes all created OAuth tables in correct order:
     * - Respects foreign key constraints
     * - Handles dependent tables
     * - Cleans up completely
     *
     * Drop order:
     * 1. Dependent tables first (refresh_tokens, access_tokens)
     * 2. Associated tables (authorization_codes)
     * 3. Supporting tables (scopes)
     * 4. Core tables (clients)
     *
     * @param SchemaManager $schema Database schema manager
     */
    public function down(SchemaManager $schema): void
    {
        $schema->dropTable('oauth_refresh_tokens');
        $schema->dropTable('oauth_access_tokens');
        $schema->dropTable('oauth_authorization_codes');
        $schema->dropTable('oauth_scopes');
        $schema->dropTable('oauth_clients');
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
        return 'Creates OAuth 2.0 server tables including clients, tokens, authorization codes, and scopes';
    }
}
