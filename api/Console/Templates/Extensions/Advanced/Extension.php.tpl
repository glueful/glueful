<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * {{EXTENSION_NAME}} Extension (Advanced)
 *
 * Advanced extension template with comprehensive feature implementation including
 * middleware, events, security, migrations, and API endpoints.
 *
 * @description {{EXTENSION_DESCRIPTION}}
 * @version 1.0.0
 * @author {{AUTHOR_NAME}}
 */
class {{EXTENSION_NAME}} extends \Glueful\Extensions
{
    /** @var array Configuration for the extension */
    private static array $config = [];
    
    /**
     * Initialize extension
     */
    public static function initialize(): void
    {
        // Load configuration if available
        if (file_exists(__DIR__ . '/src/config.php')) {
            self::$config = require __DIR__ . '/src/config.php';
        }
    }
    
    /**
     * Register extension-provided services
     */
    public static function registerServices($container = null): void
    {
        // Example: Register a custom service
        if ($container && method_exists($container, 'bind')) {
            $container->bind('{{EXTENSION_NAME}}Service', function() {
                return new \Glueful\Extensions\{{EXTENSION_NAME}}\Services\{{EXTENSION_NAME}}Service();
            });
        }
        
        // Register any other services provided by this extension
    }
    
    /**
     * Register extension middleware components
     */
    public static function registerMiddleware(): void
    {
        // Example: Register custom middleware
        $middlewareDispatcher = \Glueful\Http\Middleware\MiddlewareDispatcher::getInstance();
        // $middlewareDispatcher->add(\Glueful\Extensions\{{EXTENSION_NAME}}\Middleware\{{EXTENSION_NAME}}Middleware::class);
    }

    /**
     * Get middleware priority
     */
    public static function getMiddlewarePriority(): int
    {
        return 50; // Higher priority for advanced extensions
    }

    /**
     * Get event listeners
     */
    public static function getEventListeners(): array
    {
        return [
            'user.created' => 'onUserCreated',
            'extension.installed' => 'onExtensionInstalled',
            // Add more event listeners as needed
        ];
    }

    /**
     * Get event subscribers
     */
    public static function getEventSubscribers(): array
    {
        return [
            'system.startup' => ['onSystemStartup', 100],
            'request.processed' => ['onRequestProcessed', 50],
            // Add more event subscribers with priorities
        ];
    }

    /**
     * Handle user created event
     */
    public static function onUserCreated(array $userData): void
    {
        // Example event handler implementation
        error_log("{{EXTENSION_NAME}}: New user created with ID: " . ($userData['id'] ?? 'unknown'));
    }

    /**
     * Handle extension installed event
     */
    public static function onExtensionInstalled(array $extensionData): void
    {
        // Example event handler implementation
        if (isset($extensionData['name'])) {
            error_log("{{EXTENSION_NAME}}: Extension installed: " . $extensionData['name']);
        }
    }

    /**
     * Handle system startup event
     */
    public static function onSystemStartup(): void
    {
        // Perform startup tasks
        self::validateConfiguration();
    }

    /**
     * Handle request processed event
     */
    public static function onRequestProcessed(array $requestData): void
    {
        // Log or process request data if needed
        if (self::$config['debug'] ?? false) {
            error_log("{{EXTENSION_NAME}}: Request processed");
        }
    }

    /**
     * Validate extension configuration
     */
    private static function validateConfiguration(): void
    {
        // Validate required configuration settings
        $required = ['enabled'];
        foreach ($required as $key) {
            if (!isset(self::$config[$key])) {
                error_log("{{EXTENSION_NAME}}: Missing required configuration: $key");
            }
        }
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
                'extension' => '{{EXTENSION_NAME}}',
                'message' => '{{EXTENSION_NAME}} is working properly'
            ]
        ];
    }
    
    /**
     * Get extension metadata
     */
    public static function getMetadata(): array
    {
        return [
            'name' => '{{EXTENSION_NAME}}',
            'description' => '{{EXTENSION_DESCRIPTION}}',
            'version' => '1.0.0',
            'author' => '{{AUTHOR_NAME}}',
            'type' => '{{EXTENSION_TYPE}}',
            'requires' => [
                'glueful' => '>=0.27.0',
                'php' => '>=8.2.0',
                'extensions' => []
            ]
        ];
    }
    
    /**
     * Validate extension security
     */
    public static function validateSecurity(): array
    {
        return [
            'permissions' => [
                'extensions.manage',
                'users.read',
                // Add required permissions
            ],
            'sandbox' => false,
            'network_access' => true, // Example: needs external API access
            'file_access' => [
                'storage/{{EXTENSION_NAME|lower}}',
                'tmp/{{EXTENSION_NAME|lower}}'
            ],
            'database_access' => true, // Needs database for data storage
            'admin_only' => false,
        ];
    }

    /**
     * Get database migrations
     */
    public static function getMigrations(): array
    {
        $migrationsDir = __DIR__ . '/migrations';
        if (!is_dir($migrationsDir)) {
            return [];
        }

        // Return all migration files in order
        $migrations = glob($migrationsDir . '/*.php');
        sort($migrations);
        
        return $migrations;
    }

    /**
     * Get extension assets
     */
    public static function getAssets(): array
    {
        return [
            'css' => [
                'assets/css/{{EXTENSION_NAME|lower}}.css',
                'assets/css/{{EXTENSION_NAME|lower}}-theme.css'
            ],
            'js' => [
                'assets/js/{{EXTENSION_NAME|lower}}.js',
                'assets/js/{{EXTENSION_NAME|lower}}-admin.js'
            ],
            'images' => [
                'assets/images/icon.png',
                'assets/images/logo.svg'
            ],
            'fonts' => []
        ];
    }

    /**
     * Get API endpoints
     */
    public static function getApiEndpoints(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/v1/{{EXTENSION_NAME|lower}}',
                'handler' => [self::class, 'handleGetRequest'],
                'middleware' => ['auth'],
                'description' => 'Get {{EXTENSION_NAME}} data'
            ],
            [
                'method' => 'POST',
                'path' => '/api/v1/{{EXTENSION_NAME|lower}}',
                'handler' => [self::class, 'handlePostRequest'],
                'middleware' => ['auth', 'permissions:{{EXTENSION_NAME|lower}}.create'],
                'description' => 'Create new {{EXTENSION_NAME}} entry'
            ],
            [
                'method' => 'PUT',
                'path' => '/api/v1/{{EXTENSION_NAME|lower}}/{id}',
                'handler' => [self::class, 'handleUpdateRequest'],
                'middleware' => ['auth', 'permissions:{{EXTENSION_NAME|lower}}.update'],
                'description' => 'Update {{EXTENSION_NAME}} entry'
            ]
        ];
    }

    /**
     * Handle GET API requests
     */
    public static function handleGetRequest(array $params): array
    {
        return [
            'success' => true,
            'data' => [
                'extension' => '{{EXTENSION_NAME}}',
                'version' => '1.0.0',
                'status' => 'active'
            ]
        ];
    }

    /**
     * Handle POST API requests
     */
    public static function handlePostRequest(array $params): array
    {
        // Validate input
        $required = ['name', 'description'];
        foreach ($required as $field) {
            if (empty($params[$field])) {
                return [
                    'success' => false,
                    'error' => "Missing required field: $field"
                ];
            }
        }

        // Process the request
        return [
            'success' => true,
            'data' => [
                'id' => uniqid(),
                'message' => 'Created successfully'
            ]
        ];
    }

    /**
     * Handle PUT API requests
     */
    public static function handleUpdateRequest(array $params): array
    {
        $id = $params['id'] ?? null;
        if (!$id) {
            return [
                'success' => false,
                'error' => 'ID is required'
            ];
        }

        return [
            'success' => true,
            'data' => [
                'id' => $id,
                'message' => 'Updated successfully'
            ]
        ];
    }

    /**
     * Get configuration schema
     */
    public static function getConfigSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'enabled' => [
                    'type' => 'boolean',
                    'description' => 'Enable or disable the extension',
                    'default' => true
                ],
                'debug' => [
                    'type' => 'boolean',
                    'description' => 'Enable debug logging',
                    'default' => false
                ],
                'api_key' => [
                    'type' => 'string',
                    'description' => 'API key for external services',
                    'minLength' => 1
                ],
                'cache_timeout' => [
                    'type' => 'integer',
                    'description' => 'Cache timeout in seconds',
                    'minimum' => 60,
                    'maximum' => 3600,
                    'default' => 300
                ],
                'features' => [
                    'type' => 'object',
                    'properties' => [
                        'notifications' => [
                            'type' => 'boolean',
                            'default' => true
                        ],
                        'analytics' => [
                            'type' => 'boolean',
                            'default' => false
                        ]
                    ]
                ]
            ],
            'required' => ['enabled'],
            'additionalProperties' => false
        ];
    }

    /**
     * Check extension health
     */
    public static function checkHealth(): array
    {
        $healthy = true;
        $issues = [];
        
        // Add your health checks here
        
        return [
            'healthy' => $healthy,
            'issues' => $issues,
            'metrics' => [
                'memory_usage' => 0,
                'execution_time' => 0,
                'database_queries' => 0,
                'cache_usage' => 0
            ]
        ];
    }
}