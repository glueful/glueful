<?php

declare(strict_types=1);

namespace Glueful;

/**
 * API Documentation Generator
 *
 * Generates OpenAPI/Swagger documentation from JSON definition files.
 * Handles both table definitions and custom API endpoint documentation.
 */
class DocGenerator
{
    /** @var array OpenAPI paths storage */
    private array $paths = [];

    /** @var array OpenAPI schemas storage */
    private array $schemas = [];

    /** @var array Extension tags storage */
    private array $extensionTags = [];

    /**
     * Generate documentation from table definition
     *
     * Creates API documentation for database table endpoints.
     *
     * @param string $filename JSON definition file path
     */
    public function generateFromJson(string $filename): void
    {
        $jsonContent = file_get_contents($filename);
        if (!$jsonContent) {
            return;
        }

        $definition = json_decode($jsonContent, true);
        if (!$definition) {
            return;
        }

        $tableName = $definition['table']['name'];
        // Use table name directly instead of JSON filename
        $resourcePath = strtolower($tableName);

        $this->addPathsFromJson($resourcePath, $tableName, $definition);
        $this->addSchemaFromJson($tableName, $definition);
    }

    /**
     * Generate documentation from custom API definition
     *
     * Creates API documentation for custom endpoints.
     *
     * @param string $filename Custom API definition file path
     */
    public function generateFromDocJson(string $filename): void
    {
        $jsonContent = file_get_contents($filename);
        if (!$jsonContent) {
            return;
        }

        $definition = json_decode($jsonContent, true);
        if (!$definition || !isset($definition['doc'])) {
            return;
        }

        // Process the documentation definition
        $this->addPathsFromDocJson($definition);
        $this->addSchemaFromDocJson($definition);
    }

    /**
     * Generate documentation from extension definitions
     *
     * Finds and processes OpenAPI definition files in extensions directories.
     *
     * @param string $extensionsPath Path to the extensions directory
     */
    public function generateFromExtensions(?string $extensionsPath = null): void
    {
        if ($extensionsPath === null) {
            $extensionsPath = dirname(__DIR__) . '/docs/api-doc-json-definitions/extensions';
        }

        if (!is_dir($extensionsPath)) {
            error_log("Extensions documentation directory not found: $extensionsPath");
            return;
        }

        // Scan extension directories
        $extensionDirs = array_filter(glob($extensionsPath . '/*'), 'is_dir');

        foreach ($extensionDirs as $extDir) {
            $extName = basename($extDir);
            $extFiles = glob($extDir . '/*.json');

            foreach ($extFiles as $extFile) {
                $this->mergeExtensionDefinition($extFile, $extName);
            }
        }
    }

    /**
     * Merge extension OpenAPI definition into main documentation
     *
     * Processes an extension's OpenAPI definition file and merges
     * its components into the main API documentation.
     *
     * @param string $filePath Path to extension definition file
     * @param string $extName Extension name
     */
    private function mergeExtensionDefinition(string $filePath, string $extName): void
    {
        $jsonContent = file_get_contents($filePath);
        if (!$jsonContent) {
            error_log("Could not read extension definition file: $filePath");
            return;
        }

        $definition = json_decode($jsonContent, true);
        if (!$definition) {
            error_log("Invalid JSON in extension definition file: $filePath");
            return;
        }

        // Merge paths
        if (isset($definition['paths']) && is_array($definition['paths'])) {
            foreach ($definition['paths'] as $path => $methods) {
                // No longer add extension name to tags for better organization
                $this->paths[$path] = $methods;
            }
        }

        // Merge schemas
        if (isset($definition['components']['schemas']) && is_array($definition['components']['schemas'])) {
            foreach ($definition['components']['schemas'] as $name => $schema) {
                $this->schemas[$extName . $name] = $schema;
            }
        }

        // Merge tags
        if (isset($definition['tags']) && is_array($definition['tags'])) {
            foreach ($definition['tags'] as $tag) {
                // No longer add prefixes to tag names
                $this->extensionTags[] = $tag;
            }
        }
    }

    /**
     * Generate documentation from main routes
     *
     * Finds and processes OpenAPI definition files for main routes.
     *
     * @param string $routesPath Path to the routes documentation directory
     */
    public function generateFromRoutes(?string $routesPath = null): void
    {
        if ($routesPath === null) {
            $routesPath = dirname(__DIR__) . '/docs/api-doc-json-definitions/routes';
        }

        if (!is_dir($routesPath)) {
            error_log("Routes documentation directory not found: $routesPath");
            return;
        }

        // Process all route documentation files
        $routeFiles = glob($routesPath . '/*.json');

        foreach ($routeFiles as $routeFile) {
            $routeName = basename($routeFile, '.json');
            $this->mergeRouteDefinition($routeFile, $routeName);
        }
    }

    /**
     * Merge route OpenAPI definition into main documentation
     *
     * Processes a route's OpenAPI definition file and merges
     * its components into the main API documentation.
     *
     * @param string $filePath Path to route definition file
     * @param string $routeName Route file name
     */
    private function mergeRouteDefinition(string $filePath, string $routeName): void
    {
        $jsonContent = file_get_contents($filePath);
        if (!$jsonContent) {
            error_log("Could not read route definition file: $filePath");
            return;
        }

        $definition = json_decode($jsonContent, true);
        if (!$definition) {
            error_log("Invalid JSON in route definition file: $filePath");
            return;
        }

        // Merge paths
        if (isset($definition['paths']) && is_array($definition['paths'])) {
            foreach ($definition['paths'] as $path => $methods) {
                // No longer add prefixes to tag names for better organization
                $this->paths[$path] = $methods;
            }
        }

        // Merge schemas
        if (isset($definition['components']['schemas']) && is_array($definition['components']['schemas'])) {
            foreach ($definition['components']['schemas'] as $name => $schema) {
                $this->schemas["Route$routeName$name"] = $schema;
            }
        }

        // Merge tags
        if (isset($definition['tags']) && is_array($definition['tags'])) {
            foreach ($definition['tags'] as $tag) {
                // No longer add prefixes to tag names
                $this->extensionTags[] = $tag;
            }
        }
    }

    /**
     * Get complete OpenAPI specification
     *
     * Returns the full OpenAPI/Swagger documentation as JSON string.
     *
     * @return string OpenAPI specification JSON
     */
    public function getSwaggerJson(): string
    {
        $swagger = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => config('app.name'),
                'version' => config('app.version_full'),
                'description' => 'Auto-generated API documentation',
            ],
            'servers' => [
                [
                    'url' => rtrim(config('app.paths.api_base_url'), '/') . '/' . config('app.api_version'),
                    'description' => 'API Server ' . config('app.api_version')
                ]
            ],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                        'description' => 'JWT Authorization header using the Bearer scheme'
                    ]
                ],
                'schemas' => array_merge($this->getDefaultSchemas(), $this->schemas)
            ],
            'paths' => $this->paths,
            'tags' => $this->generateTags()
        ];

        return json_encode($swagger, JSON_PRETTY_PRINT);
    }

    /**
     * Add paths from table definition
     *
     * Generates endpoint documentation for standard CRUD operations.
     *
     * @param string $resource API resource name
     * @param string $tableName Database table name
     * @param array $definition Table definition data
     */
    private function addPathsFromJson(string $resource, string $tableName, array $definition): void
    {
        $access = $definition['access']['mode'] ?? 'r';
        $basePath = "/{$resource}";
        $this->paths[$basePath] = [];

        // For views (starting with vw_), only add GET method
        if (str_starts_with($tableName, 'vw_')) {
            $this->paths[$basePath]['get'] = [
                // 'tags' => [$resource],
                'tags' => ["Table - {$resource}"],
                'summary' => "List {$tableName}",
                'description' => "View-only endpoint for {$tableName}",
                'parameters' => [
                    ...$this->getCommonParameters(),
                    ...$this->getFilterParameters()
                ],
                'responses' => $this->getCommonResponses($tableName)
            ];
            return;
        }

        // Rest of the CRUD operations remain the same
        if (str_contains($access, 'r')) {
            $this->paths[$basePath]['get'] = [
                // 'tags' => [$resource],
                'tags' => ["Table - {$resource}"],
                'summary' => "List {$tableName}",
                'description' => "Retrieve a list of {$tableName} records",
                'security' => [['BearerAuth' => []]],
                'parameters' => [
                    ...$this->getCommonParameters(),
                    ...$this->getFilterParameters()
                ],
                'responses' => $this->getCommonResponses($tableName)
            ];
        }

        if (str_contains($access, 'w')) {
            // POST uses schema without ID
            $this->paths[$basePath]['post'] = [
                // 'tags' => [$resource],
                'tags' => ["Table - {$resource}"],
                'summary' => "Create new {$tableName}",
                'description' => "Create a new {$tableName} record",
                'security' => [['BearerAuth' => []]],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$tableName}"]
                        ]
                    ]
                ],
                'responses' => [
                    '201' => [
                        'description' => 'Record created successfully',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => "#/components/schemas/{$tableName}Update"]
                            ]
                        ]
                    ],
                    ...$this->getErrorResponses()
                ]
            ];

            // PUT uses schema with ID
            $this->paths[$basePath . '/{id}']['put'] = [
                // 'tags' => [$resource],
                'tags' => ["Table - {$resource}"],
                'summary' => "Update {$tableName}",
                'description' => "Update an existing {$tableName} record",
                'security' => [['BearerAuth' => []]],
                'parameters' => [
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'integer']
                    ],
                    ...$this->getCommonParameters()
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$tableName}Update"]
                        ]
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Record updated successfully',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => "#/components/schemas/{$tableName}"]
                            ]
                        ]
                    ],
                    ...$this->getErrorResponses()
                ]
            ];

            $this->paths[$basePath . '/{id}']['delete'] = [
                // 'tags' => [$resource],
                'tags' => ["Table - {$resource}"],
                'summary' => "Delete {$tableName}",
                'description' => "Delete a {$tableName} record",
                'security' => [['BearerAuth' => []]],
                'parameters' => [
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'integer']
                    ],
                    ...$this->getCommonParameters()
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Record deleted successfully'
                    ],
                    ...$this->getErrorResponses()
                ]
            ];
        }
    }

    /**
     * Add paths from custom API definition
     *
     * Generates endpoint documentation for custom API endpoints.
     *
     * @param array $definition Custom API definition
     */
    private function addPathsFromDocJson(array $definition): void
    {
        $docName = $definition['doc']['name'];
        $method = strtolower($definition['doc']['method']);
        $isPublic = $definition['doc']['is_public'] ?? false;
        $consumes = $definition['doc']['consumes'] ?? ['application/json'];

        $basePath = "/{$docName}";
        $this->paths[$basePath] = [];

        // Build request schema
        $properties = [];
        $required = [];

        foreach ($definition['doc']['fields'] as $field) {
            $fieldName = $field['name'];
            $apiField = $field['api_field'] ?? $fieldName;

            $properties[$apiField] = [
                'type' => $this->inferTypeFromJson($field['type']),
                'description' => $field['description'] ?? $fieldName
            ];

            if (!($field['nullable'] ?? true)) {
                $required[] = $apiField;
            }
        }

        // Create schema for this endpoint
        $schemaName = str_replace(['/'], '', ucwords($docName, '/'));
        $this->schemas[$schemaName] = [
            'type' => 'object',
            'properties' => $properties
        ];

        if (!empty($required)) {
            $this->schemas[$schemaName]['required'] = $required;
        }

        // Build content object based on consumes array
        $content = [];
        foreach ($consumes as $mediaType) {
            if ($mediaType === 'multipart/form-data') {
                $content[$mediaType] = [
                    'schema' => [
                        'type' => 'object',
                        'properties' => $properties
                    ]
                ];
            } else {
                $content[$mediaType] = [
                    'schema' => ['$ref' => "#/components/schemas/{$schemaName}"]
                ];
            }
        }

        // Add custom response schema if provided
        if (isset($definition['doc']['response'])) {
            $responseSchemaName = $schemaName . 'Response';
            $this->schemas[$responseSchemaName] = $definition['doc']['response'];
            $responses = [
                '200' => [
                    'description' => 'Successful operation',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => "#/components/schemas/{$responseSchemaName}"]
                        ]
                    ]
                ],
                ...$this->getErrorResponses()
            ];
        } else {
            $responses = $this->getCommonResponses($schemaName);
        }

        // Add path operation
        $this->paths[$basePath][$method] = [
            'tags' => [explode('/', $docName)[0]],
            'summary' => ucwords(str_replace('-', ' ', basename($docName))),
            'description' => "Endpoint for " . str_replace('-', ' ', $docName),
            'security' => $isPublic ? [] : [['BearerAuth' => []]],
            'requestBody' => [
                'required' => true,
                'content' => $content
            ],
            'responses' => $responses
        ];
    }

    /**
     * Process table fields
     *
     * Validates and processes field definitions from table configuration.
     *
     * @param array $definition Table definition
     * @return array Processed fields
     */
    private function processFields(array $definition): array
    {
        // Log the full definition for debugging
        // error_log("Processing definition: " . json_encode($definition, JSON_PRETTY_PRINT));

        if (!isset($definition['table']) || !isset($definition['fields'])) {
            $tableName = $definition['name'] ?? 'unknown';
            // error_log("Table structure missing for: $tableName");
            return [];
        }

        // Verify fields is an array
        if (!is_array($definition['fields'])) {
            $tableName = $definition['name'] ?? 'unknown';
            error_log("Fields is not an array for table: $tableName");
            return [];
        }

        return $definition['fields'];
    }

    /**
     * Add schema from table definition
     *
     * Creates OpenAPI schemas for table data structures.
     *
     * @param string $tableName Database table name
     * @param array $definition Table definition data
     */
    private function addSchemaFromJson(string $tableName, array $definition): void
    {
        $properties = [];
        $required = [];

        $fields = $this->processFields($definition['table'] ?? []);
        if (empty($fields)) {
            // Handle missing or empty fields gracefully
            return;
        }

        foreach ($fields as $field) {
            $fieldName = $field['name'];
            $apiField = $field['api_field'] ?? $fieldName;

            // Skip ID and UUID fields for POST schema
            if (strtolower($fieldName) === 'id' || strtolower($fieldName) === 'uuid') {
                continue;
            }
            $fieldType = $field['type'] ?? '';
            $properties[$apiField] = [
                'type' => $this->inferTypeFromJson($fieldType),
                'description' => $field['description'] ?? $fieldName
            ];

            if (!($field['nullable'] ?? true)) {
                $required[] = $apiField;
            }
        }

        $this->schemas[$tableName] = [
            'type' => 'object',
            'properties' => $properties
        ];

        if (!empty($required)) {
            $this->schemas[$tableName]['required'] = $required;
        }

        // Create separate schema for PUT/PATCH that includes ID
        $this->schemas[$tableName . 'Update'] = [
            'type' => 'object',
            'properties' => array_merge(
                [
                    'id' => ['type' => 'string', 'format' => 'uuid'],
                    'uuid' => ['type' => 'string', 'format' => 'uuid']
                ],
                $properties
            )
        ];
    }

    /**
     * Add schema from custom API definition
     *
     * Creates OpenAPI schemas for custom API endpoints.
     *
     * @param array $definition Custom API definition
     * @return string Generated schema name
     */
    private function addSchemaFromDocJson(array $definition): string
    {
        $docName = $definition['doc']['name'];
        $schemaName = str_replace(['/'], '', ucwords($docName, '/'));

        $properties = [];
        $required = [];

        foreach ($definition['doc']['fields'] as $field) {
            $fieldName = $field['name'];
            $apiField = $field['api_field'] ?? $fieldName;

            $properties[$apiField] = [
                'type' => $this->inferTypeFromJson($field['type']),
                'description' => $field['description'] ?? $fieldName
            ];

            // Special handling for file type
            if ($field['type'] === 'file') {
                $properties[$apiField]['format'] = 'binary';
            }
            // Special handling for base64
            if ($field['type'] === 'longtext' && str_contains($field['description'], 'base64')) {
                $properties[$apiField]['format'] = 'base64';
            }

            if (!($field['nullable'] ?? true)) {
                $required[] = $apiField;
            }
        }

        // Create request schema
        $this->schemas[$schemaName] = [
            'type' => 'object',
            'properties' => $properties
        ];

        if (!empty($required)) {
            $this->schemas[$schemaName]['required'] = $required;
        }

        // Create response schema
        if (isset($definition['doc']['response'])) {
            $this->schemas[$schemaName . 'Response'] = $definition['doc']['response'];
        }

        // Create multipart schema if needed
        if (isset($definition['doc']['consumes']) && in_array('multipart/form-data', $definition['doc']['consumes'])) {
            $this->schemas[$schemaName . 'Multipart'] = [
                'type' => 'object',
                'properties' => array_map(function ($prop) {
                    // Convert file properties for multipart
                    if (isset($prop['format']) && $prop['format'] === 'binary') {
                        return [
                            'type' => 'string',
                            'format' => 'binary',
                            'description' => $prop['description']
                        ];
                    }
                    return $prop;
                }, $properties)
            ];
        }

        return $schemaName;
    }

    /**
     * Infer OpenAPI type from database type
     *
     * Maps database column types to OpenAPI data types.
     *
     * @param string $dbType Database column type
     * @return string OpenAPI data type
     */
    private function inferTypeFromJson(string $dbType): string
    {
        // Handle null or empty type
        if (!$dbType) {
            return 'string'; // Default to string type
        }
        if (str_contains($dbType, 'int')) {
            return 'integer';
        }
        if (str_contains($dbType, 'decimal') || str_contains($dbType, 'float') || str_contains($dbType, 'double')) {
            return 'number';
        }
        if (str_contains($dbType, 'datetime') || str_contains($dbType, 'timestamp')) {
            return 'string';
        }
        if (str_contains($dbType, 'bool')) {
            return 'boolean';
        }
        return 'string';
    }

    /**
     * Get common API parameters
     *
     * Returns standard parameters used across endpoints.
     *
     * @return array Common parameters definition
     */
    private function getCommonParameters(): array
    {
        return [
            [
                'name' => 'fields',
                'in' => 'query',
                'description' => 'Comma-separated list of fields to return',
                'schema' => ['type' => 'string']
            ]
        ];
    }

    /**
     * Get filter parameters
     *
     * Returns standard filtering and pagination parameters.
     *
     * @return array Filter parameters definition
     */
    private function getFilterParameters(): array
    {
        return [
            [
                'name' => 'filter',
                'in' => 'query',
                'description' => 'Filter criteria',
                'schema' => ['type' => 'string']
            ],
            [
                'name' => 'orderby',
                'in' => 'query',
                'description' => 'Sort order (field:direction)',
                'schema' => ['type' => 'string']
            ],
            [
                'name' => 'limit',
                'in' => 'query',
                'schema' => ['type' => 'integer', 'default' => 20]
            ],
            [
                'name' => 'offset',
                'in' => 'query',
                'schema' => ['type' => 'integer', 'default' => 0]
            ]
        ];
    }

    /**
     * Get common response definitions
     *
     * Returns standard API response structures.
     *
     * @param string $tableName Related table name
     * @return array Response definitions
     */
    private function getCommonResponses(string $tableName): array
    {
        return [
            '200' => [
                'description' => 'Successful operation',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'success' => [
                                    'type' => 'boolean',
                                    'default' => true,
                                    'example' => true
                                ],
                                'message' => [
                                    'type' => 'string'
                                ],
                                'data' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => "#/components/schemas/{$tableName}"]
                                ]
                                // 'meta' => [
                                //     '$ref' => '#/components/schemas/PaginationMeta'
                                // ]
                            ],
                            'required' => ['success', 'message', 'data']
                        ]
                    ]
                ]
            ],
            '401' => [
                'description' => 'Unauthorized',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/Error'
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Get error response definitions
     *
     * Returns standard error response structures.
     *
     * @return array Error response definitions
     */
    private function getErrorResponses(): array
    {
        return [
            '400' => [
                'description' => 'Bad request',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/Error'
                        ]
                    ]
                ]
            ],
            '401' => [
                'description' => 'Unauthorized',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/Error'
                        ]
                    ]
                ]
            ],
            '404' => [
                'description' => 'Record not found',
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => '#/components/schemas/Error'
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Get default OpenAPI schemas
     *
     * Returns base schemas used across the API.
     *
     * @return array Default schemas
     */
    private function getDefaultSchemas(): array
    {
        return [
            // Common Response Schemas
            'SuccessResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean',
                        'example' => true
                    ],
                    'message' => [
                        'type' => 'string',
                        'example' => 'Operation completed successfully'
                    ],
                    'data' => [
                        'type' => 'object',
                        'additionalProperties' => true
                    ]
                ],
                'required' => ['success', 'message']
            ],

            'Error' => [
                'type' => 'object',
                'properties' => [
                   'success' => [
                        'type' => 'boolean',
                        'default' => false,
                        'example' => false
                    ],
                    'message' => [
                        'type' => 'string'
                    ],
                    'data' => [
                        'type' => 'object',
                        'additionalProperties' => true
                    ]
                ],
                'required' => ['success', 'message', 'data']
            ],

            'ErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean',
                        'example' => false
                    ],
                    'message' => [
                        'type' => 'string',
                        'example' => 'An error occurred'
                    ],
                    'errors' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string'
                        ]
                    ]
                ],
                'required' => ['success', 'message']
            ],

            'ValidationErrorResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean',
                        'example' => false
                    ],
                    'message' => [
                        'type' => 'string',
                        'example' => 'Validation failed'
                    ],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'string'
                            ]
                        ]
                    ]
                ],
                'required' => ['success', 'message', 'errors']
            ],

            'PaginationMeta' => [
                'type' => 'object',
                'properties' => [
                    'current_page' => [
                        'type' => 'integer',
                        'description' => 'Current page number'
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'description' => 'Number of items per page'
                    ],
                    'total' => [
                        'type' => 'integer',
                        'description' => 'Total number of items'
                    ],
                    'last_page' => [
                        'type' => 'integer',
                        'description' => 'Last page number'
                    ],
                    'has_more' => [
                        'type' => 'boolean',
                        'description' => 'Whether more pages exist'
                    ],
                    'from' => [
                        'type' => 'integer',
                        'description' => 'Starting item number on current page'
                    ],
                    'to' => [
                        'type' => 'integer',
                        'description' => 'Ending item number on current page'
                    ]
                ]
            ],

            // Authentication Schemas
            'LoginRequest' => [
                'type' => 'object',
                'required' => ['username', 'password'],
                'properties' => [
                    'username' => [
                        'type' => 'string',
                        'description' => 'Username or email'
                    ],
                    'password' => [
                        'type' => 'string',
                        'format' => 'password',
                        'description' => 'User password'
                    ],
                    'remember_me' => [
                        'type' => 'boolean',
                        'description' => 'Keep user logged in',
                        'default' => false
                    ]
                ]
            ],

            'LoginResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean'
                    ],
                    'message' => [
                        'type' => 'string'
                    ],
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'access_token' => [
                                'type' => 'string',
                                'description' => 'JWT access token'
                            ],
                            'refresh_token' => [
                                'type' => 'string',
                                'description' => 'JWT refresh token'
                            ],
                            'token_type' => [
                                'type' => 'string',
                                'example' => 'Bearer'
                            ],
                            'expires_in' => [
                                'type' => 'integer',
                                'description' => 'Token expiration time in seconds'
                            ],
                            'user' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => [
                                        'type' => 'string',
                                        'description' => 'User unique identifier'
                                    ],
                                    'email' => [
                                        'type' => 'string',
                                        'format' => 'email',
                                        'description' => 'Email address'
                                    ],
                                    'email_verified' => [
                                        'type' => 'boolean',
                                        'description' => 'Email verification status'
                                    ],
                                    'username' => [
                                        'type' => 'string',
                                        'description' => 'Username'
                                    ],
                                    'name' => [
                                        'type' => 'string',
                                        'description' => 'Full name'
                                    ],
                                    'given_name' => [
                                        'type' => 'string',
                                        'description' => 'First name'
                                    ],
                                    'family_name' => [
                                        'type' => 'string',
                                        'description' => 'Last name'
                                    ],
                                    'picture' => [
                                        'type' => 'string',
                                        'description' => 'Profile image URL'
                                    ],
                                    'locale' => [
                                        'type' => 'string',
                                        'description' => 'User locale (e.g., en-US)'
                                    ],
                                    'updated_at' => [
                                        'type' => 'integer',
                                        'description' => 'Last update timestamp (Unix epoch)'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],

            'RefreshTokenRequest' => [
                'type' => 'object',
                'required' => ['refresh_token'],
                'properties' => [
                    'refresh_token' => [
                        'type' => 'string',
                        'description' => 'The refresh token to exchange for new tokens'
                    ]
                ]
            ],

            // User Management Schemas
            'User' => [
                'type' => 'object',
                'properties' => [
                    'uuid' => [
                        'type' => 'string',
                        'format' => 'uuid',
                        'description' => 'Unique user identifier'
                    ],
                    'username' => [
                        'type' => 'string',
                        'description' => 'User username'
                    ],
                    'email' => [
                        'type' => 'string',
                        'format' => 'email',
                        'description' => 'User email address'
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['active', 'inactive', 'suspended'],
                        'description' => 'User account status'
                    ],
                    'created_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'Account creation timestamp'
                    ],
                    'updated_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'Last update timestamp'
                    ]
                ]
            ],

            'CreateUserRequest' => [
                'type' => 'object',
                'required' => ['username', 'email', 'password'],
                'properties' => [
                    'username' => [
                        'type' => 'string',
                        'description' => 'Unique username'
                    ],
                    'email' => [
                        'type' => 'string',
                        'format' => 'email',
                        'description' => 'User email address'
                    ],
                    'password' => [
                        'type' => 'string',
                        'format' => 'password',
                        'description' => 'User password'
                    ]
                ]
            ],

            'UpdateUserRequest' => [
                'type' => 'object',
                'properties' => [
                    'email' => [
                        'type' => 'string',
                        'format' => 'email',
                        'description' => 'New email address'
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['active', 'inactive', 'suspended'],
                        'description' => 'New account status'
                    ]
                ]
            ],

            // Health Check Schemas
            'HealthCheckResponse' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['healthy', 'unhealthy'],
                        'description' => 'Overall system health status'
                    ],
                    'timestamp' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'Health check timestamp'
                    ],
                    'services' => [
                        'type' => 'object',
                        'properties' => [
                            'database' => [
                                '$ref' => '#/components/schemas/ServiceHealth'
                            ],
                            'cache' => [
                                '$ref' => '#/components/schemas/ServiceHealth'
                            ],
                            'queue' => [
                                '$ref' => '#/components/schemas/ServiceHealth'
                            ]
                        ]
                    ]
                ]
            ],

            'ServiceHealth' => [
                'type' => 'object',
                'properties' => [
                    'status' => [
                        'type' => 'string',
                        'enum' => ['up', 'down'],
                        'description' => 'Service availability status'
                    ],
                    'latency' => [
                        'type' => 'number',
                        'description' => 'Response time in milliseconds'
                    ],
                    'message' => [
                        'type' => 'string',
                        'description' => 'Additional status information'
                    ]
                ]
            ],

            // Extension Schemas
            'Extension' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Extension name'
                    ],
                    'version' => [
                        'type' => 'string',
                        'description' => 'Extension version'
                    ],
                    'status' => [
                        'type' => 'string',
                        'enum' => ['enabled', 'disabled'],
                        'description' => 'Extension status'
                    ],
                    'type' => [
                        'type' => 'string',
                        'enum' => ['core', 'optional'],
                        'description' => 'Extension type'
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Extension description'
                    ],
                    'dependencies' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'string'
                        ],
                        'description' => 'Required dependencies'
                    ]
                ]
            ],

            'ExtensionListResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean'
                    ],
                    'data' => [
                        'type' => 'array',
                        'items' => [
                            '$ref' => '#/components/schemas/Extension'
                        ]
                    ]
                ]
            ],

            // Notification Schemas
            'Notification' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => 'Notification ID'
                    ],
                    'type' => [
                        'type' => 'string',
                        'description' => 'Notification type'
                    ],
                    'notifiable_type' => [
                        'type' => 'string',
                        'description' => 'Type of entity being notified'
                    ],
                    'notifiable_id' => [
                        'type' => 'string',
                        'description' => 'ID of entity being notified'
                    ],
                    'data' => [
                        'type' => 'object',
                        'description' => 'Notification payload'
                    ],
                    'read_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'nullable' => true,
                        'description' => 'When notification was read'
                    ],
                    'created_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => 'When notification was created'
                    ]
                ]
            ],

            'NotificationListResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean'
                    ],
                    'data' => [
                        'type' => 'array',
                        'items' => [
                            '$ref' => '#/components/schemas/Notification'
                        ]
                    ],
                    'meta' => [
                        '$ref' => '#/components/schemas/PaginationMeta'
                    ]
                ]
            ],

            // File Upload Schemas
            'FileUploadRequest' => [
                'type' => 'object',
                'required' => ['file'],
                'properties' => [
                    'file' => [
                        'type' => 'string',
                        'format' => 'binary',
                        'description' => 'The file to upload'
                    ]
                ]
            ],

            'FileUploadResponse' => [
                'type' => 'object',
                'properties' => [
                    'success' => [
                        'type' => 'boolean'
                    ],
                    'data' => [
                        'type' => 'object',
                        'properties' => [
                            'filename' => [
                                'type' => 'string',
                                'description' => 'Uploaded file name'
                            ],
                            'size' => [
                                'type' => 'integer',
                                'description' => 'File size in bytes'
                            ],
                            'mime_type' => [
                                'type' => 'string',
                                'description' => 'File MIME type'
                            ],
                            'url' => [
                                'type' => 'string',
                                'description' => 'File access URL'
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Generate API tags
     *
     * Creates OpenAPI tags for grouping endpoints.
     *
     * @return array Generated tags
     */
    private function generateTags(): array
    {
        $tags = [];
        foreach ($this->paths as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (isset($operation['tags'])) {
                    foreach ($operation['tags'] as $tag) {
                        if (!isset($tags[$tag])) {
                            $tags[$tag] = [
                                'name' => $tag,
                                'description' => "Operations related to {$tag}"
                            ];
                        }
                    }
                }
            }
        }

        // Merge extension tags with auto-generated tags
        return array_values(array_merge($tags, $this->extensionTags));
    }
}
