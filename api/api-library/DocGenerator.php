<?php
declare(strict_types=1);

namespace Mapi\Api\Library;

class DocGenerator 
{
    private array $paths = [];
    private array $schemas = [];


    public function generateFromJson(string $dbResource, string $filename): void 
    {
        $jsonContent = file_get_contents($filename);
        if (!$jsonContent) return;

        $definition = json_decode($jsonContent, true);
        if (!$definition) return;

        $tableName = $definition['table']['name'];
        $resourceName = explode('.', basename($filename))[1] ?? $tableName;
        $this->addPathsFromJson($resourceName, $tableName, $definition);
        $this->addSchemaFromJson($tableName, $definition);
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
                    ],
                    'ApiKeyAuth' => [
                        'type' => 'apiKey',
                        'in' => 'query',
                        'name' => 'token',
                        'description' => 'API key for legacy authentication'
                    ]
                ],
                'schemas' => array_merge($this->getDefaultSchemas(), $this->schemas)
            ],
            'paths' => $this->paths,
            'security' => [
                ['BearerAuth' => []],
                ['ApiKeyAuth' => []]
            ],
            'tags' => $this->generateTags()
        ];

        return json_encode($swagger, JSON_PRETTY_PRINT);
    }

    private function addPathsFromXml(string $resource, string $tableName, \SimpleXMLElement $xml): void 
    {
        $access = (string)$xml->access->attributes()->mode;
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

        // For regular tables, add all CRUD methods
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

        // Add write methods only for non-view tables
        if (str_contains($access, 'w')) {
            // Create endpoint (POST)
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
                                'schema' => ['$ref' => "#/components/schemas/{$tableName}"]
                            ]
                        ]
                    ],
                    ...$this->getErrorResponses()
                ]
            ];

            // Update endpoint (PUT)
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
                            'schema' => ['$ref' => "#/components/schemas/{$tableName}"]
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

            // Delete endpoint (DELETE)
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
                                'schema' => ['$ref' => "#/components/schemas/{$tableName}"]
                            ]
                        ]
                    ],
                    ...$this->getErrorResponses()
                ]
            ];

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
                            'schema' => ['$ref' => "#/components/schemas/{$tableName}"]
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

    private function addSchemaFromJson(string $tableName, array $definition): void 
    {
        $properties = [];
        $required = [];

        foreach ($definition['table']['fields'] as $field) {
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

        $this->schemas[$tableName] = [
            'type' => 'object',
            'properties' => $properties
        ];

        if (!empty($required)) {
            $this->schemas[$tableName]['required'] = $required;
        }
    }

    private function inferType(\SimpleXMLElement $field): string 
    {
        $type = (string)($field->attributes()->type ?? '');
        return match($type) {
            'int', 'integer', 'bigint' => 'integer',
            'float', 'decimal', 'double' => 'number',
            'boolean', 'bool' => 'boolean',
            'date', 'datetime' => 'string',
            default => 'string'
        };
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
                'name' => 'token',
                'in' => 'query',
                'required' => true,
                'schema' => ['type' => 'string']
            ],
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
                                'data' => [
                                    'type' => 'array',
                                    'items' => ['$ref' => "#/components/schemas/{$tableName}"]
                                ],
                                'meta' => [
                                    '$ref' => '#/components/schemas/PaginationMeta'
                                ]
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
                    'code' => [
                        'type' => 'integer',
                        'format' => 'int32'
                    ],
                    'message' => [
                        'type' => 'string'
                    ],
                    'details' => [
                        'type' => 'object',
                        'additionalProperties' => true
                    ]
                ],
                'required' => ['code', 'message']
            ],
            'PaginationMeta' => [
                'type' => 'object',
                'properties' => [
                    'total' => ['type' => 'integer'],
                    'limit' => ['type' => 'integer'],
                    'offset' => ['type' => 'integer'],
                    'pages' => ['type' => 'integer']
                ]
            ]
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
