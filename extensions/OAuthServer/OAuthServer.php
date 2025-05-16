<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\Auth\AuthBootstrap;
use Glueful\Http\Router;

/**
 * OAuthServer Extension
 *
 * @description OAuth server implementation that handles different grant types
 * @version 1.0.0
 */
class OAuthServer extends \Glueful\Extensions
{
    /** @var array Configuration for the extension */
    private static array $config = [];

    /**
     * Initialize extension
     */
    public static function initialize(): void
    {

        self::$config = [
            'access_token_ttl' => 3600,             // 1 hour
            'refresh_token_ttl' => 2592000,         // 30 days
            'auth_code_ttl' => 600,                 // 10 minutes
            'enable_implicit_grant' => false,       // Disabled by default for security
            'require_pkce' => true,                 // Require PKCE for auth code grant
            'allow_public_clients' => false,        // Whether to allow clients without a secret
            'rotation_strategy' => 'single_use',    // Strategy for refresh token rotation
        ];

        // Include routes file
        require_once __DIR__ . '/routes.php';

        // Register necessary service classes
        self::registerServices();
    }

    /**
     * Register extension-provided services
     */
    public static function registerServices(): void
    {
        // Register OAuth-based authentication provider
        $authManager = AuthBootstrap::getManager();
        $authManager->registerProvider('oauth', new \Glueful\Extensions\OAuthServer\Auth\OAuthAuthenticationProvider());
    }

    /**
     * Register extension middleware components
     */
    public static function registerMiddleware(): void
    {
        // No middleware needed at this point
    }

    /**
     * Process extension requests
     *
     * @param array $queryParams GET parameters
     * @param array $bodyParams POST parameters
     * @return array Response data
     */
    public static function process(array $queryParams, array $bodyParams): array
    {
        return [
            'success' => true,
            'data' => [
                'extension' => 'OAuthServer',
                'message' => 'OAuthServer is working properly'
            ]
        ];
    }

    /**
     * Get extension metadata
     */
    public static function getMetadata(): array
    {
        return [
            'name' => 'OAuthServer',
            'description' => 'OAuth server implementation that handles different grant types',
            'version' => '1.0.0',
            'author' => 'Glueful Team',
            'type' => 'optional',
            'requires' => [
                'glueful' => '>=1.0.0',
                'php' => '>=8.1.0',
                'extensions' => []
            ]
        ];
    }

    /**
     * Check extension health
     *
     * Verifies required OAuth tables exist and are accessible.
     *
     * @return array Health status with issues if any
     */
    public static function checkHealth(): array
    {
        $healthy = true;
        $issues = [];

        // Check if database tables are properly created
        try {
            $tables = [
                'oauth_clients',
                'oauth_access_tokens',
                'oauth_refresh_tokens',
                'oauth_authorization_codes',
                'oauth_scopes'
            ];
            $connection = new \Glueful\Database\Connection();
            $schema = $connection->getSchemaManager();

            foreach ($tables as $table) {
                if (!$schema->tableExists($table)) {
                    $healthy = false;
                    $issues[] = "Table '{$table}' doesn't exist. Make sure you've run the database migrations.";
                }
            }
        } catch (\Exception $e) {
            $healthy = false;
            $issues[] = "Database connection error: " . $e->getMessage();
        }

        return [
            'healthy' => $healthy,
            'issues' => $issues
        ];
    }
}
