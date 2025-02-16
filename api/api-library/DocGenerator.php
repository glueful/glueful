<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

class DocGenerator 
{
    private array $paths = [];
    private array $schemas = [];


    public function generateFromJson(string $filename): void 
    {
        $jsonContent = file_get_contents($filename);
        if (!$jsonContent) return;

        $definition = json_decode($jsonContent, true);
        if (!$definition) return;

        $tableName = $definition['table']['name'];
        // Use table name directly instead of JSON filename
        $resourcePath = strtolower($tableName);
        
        $this->addPathsFromJson($resourcePath, $tableName, $definition);
        $this->addSchemaFromJson($tableName, $definition);
    }

    public function generateFromDocJson(string $filename): void 
    {
        $jsonContent = file_get_contents($filename);
        if (!$jsonContent) return;

        $definition = json_decode($jsonContent, true);
        if (!$definition || !isset($definition['doc'])) return;

        // Process the documentation definition
        $this->addPathsFromDocJson($definition);
        $this->addSchemaFromDocJson($definition);
    }

    public function getSwaggerJson(): string 
    {
        $swagger = [
            'openapi' => '3.0.0',
            'info' => [
                'title' => config('app.title'),
                'version' => config('app.api_version'),
                'description' => 'Auto-generated API documentation',
                'contact' => [
                    'name' => 'API Support',
                    'email' => 'support@example.com'
                ],
                'license' => [
                    'name' => 'MIT',
                    'url' => 'https://opensource.org/licenses/MIT'
                ]
            ],
            'servers' => [
                [
                    'url' => config('paths.api_base_url'),
                    'description' => 'Production API Server'
                ],
                [
                    'url' => str_replace('api', 'staging-api', config('paths.api_base_url')),
                    'description' => 'Staging API Server'
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
            'security' => [
                ['BearerAuth' => []]
            ],
            'tags' => $this->generateTags()
        ];

        return json_encode($swagger, JSON_PRETTY_PRINT);
    }


    private function addPathsFromJson(string $resource, string $tableName, array $definition): void 
    {
        $access = $definition['access']['mode'] ?? 'r';
        $basePath = "/{$resource}";
        $this->paths[$basePath] = [];

        // For views (starting with vw_), only add GET method
        if (str_starts_with($tableName, 'vw_')) {
            $this->paths[$basePath]['get'] = [
                'tags' => [$resource],
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
                'tags' => [$resource],
                'summary' => "List {$tableName}",
                'description' => "Retrieve a list of {$tableName} records",
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
                'tags' => [$resource],
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
                'tags' => [$resource],
                'summary' => "Update {$tableName}",
                'description' => "Update an existing {$tableName} record",
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
                'tags' => [$resource],
                'summary' => "Delete {$tableName}",
                'description' => "Delete a {$tableName} record",
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

    private function addSchemaFromJson(string $tableName, array $definition): void 
    {
        $properties = [];
        $required = [];

        foreach ($definition['table']['fields'] as $field) {
            $fieldName = $field['name'];
            $apiField = $field['api_field'] ?? $fieldName;
            
            // Skip ID and UUID fields for POST schema
            if (strtolower($fieldName) === 'id' || strtolower($fieldName) === 'uuid') {
                continue;
            }
            
            $properties[$apiField] = [
                'type' => $this->inferTypeFromJson($field['type']),
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
                'properties' => array_map(function($prop) {
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

    private function inferTypeFromJson(string $dbType): string 
    {
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
                                ],
                                'code' => [
                                    'type' => 'integer',
                                    'format' => 'int32',
                                    'enum' => [200, 201],
                                    'example' => 200,
                                ],
                                'required' => ['success', 'message', 'data', 'code']
                                // 'meta' => [
                                //     '$ref' => '#/components/schemas/PaginationMeta'
                                // ]
                            ]
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

    private function getDefaultSchemas(): array 
    {
        return [
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
                    ],
                    'code' => [
                        'type' => 'integer',
                        'format' => 'int32',
                        'enum' => [400, 401, 403, 404, 500],
                        'example' => 400,
                    ],
                ],
                'required' => ['success', 'message', 'data', 'code']
            ],
            // 'PaginationMeta' => [
            //     'type' => 'object',
            //     'properties' => [
            //         'total' => ['type' => 'integer'],
            //         'limit' => ['type' => 'integer'],
            //         'offset' => ['type' => 'integer'],
            //         'pages' => ['type' => 'integer']
            //     ]
            // ]
        ];
    }

    private function generateTags(): array 
    {
        $tags = [];
        foreach ($this->paths as $path => $methods) {
            if (isset($methods['get'])) {
                $tag = $methods['get']['tags'][0] ?? '';
                if ($tag && !isset($tags[$tag])) {
                    $tags[$tag] = [
                        'name' => $tag,
                        'description' => "Operations related to {$tag}"
                    ];
                }
            }
        }
        return array_values($tags);
    }
}
