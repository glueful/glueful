<?php

declare(strict_types=1);

namespace Glueful;

use ReflectionClass;
use ReflectionMethod;
use Glueful\Helpers\ExtensionsManager;

/**
 * Comments Documentation Generator
 *
 * Generates OpenAPI documentation for routes by:
 * - Scanning extension directories for route files
 * - Extracting route documentation from doc comments
 * - Generating OpenAPI specifications
 */
class CommentsDocGenerator
{
    /** @var string Base path to extensions directory */
    private string $extensionsPath;

    /** @var string Base path to routes directory */
    private string $routesPath;

    /** @var string Output directory for generated extension documentation */
    private string $outputPath;

    /** @var string Output directory for generated routes documentation */
    private string $routesOutputPath;

    /** @var array Processed route information */
    private array $routeData = [];

    /** @var ExtensionsManager Extensions manager for checking enabled extensions */
    private ExtensionsManager $extensionsManager;

    /**
     * Constructor
     *
     * @param string|null $extensionsPath Custom path to extensions directory
     * @param string|null $outputPath Custom output path for extension documentation
     * @param string|null $routesPath Custom path to routes directory
     * @param string|null $routesOutputPath Custom output path for routes documentation
     * @param ExtensionsManager|null $extensionsManager Extensions manager instance
     */
    public function __construct(
        ?string $extensionsPath = null,
        ?string $outputPath = null,
        ?string $routesPath = null,
        ?string $routesOutputPath = null,
        ?ExtensionsManager $extensionsManager = null
    ) {
        $this->extensionsPath = $extensionsPath ?? config(('app.paths.project_extensions'));
        $this->outputPath = $outputPath ?? dirname(__DIR__) . '/docs/api-doc-json-definitions/extensions';
        $this->routesPath = $routesPath ?? dirname(__DIR__) . '/routes';
        $this->routesOutputPath = $routesOutputPath ?? dirname(__DIR__) . '/docs/api-doc-json-definitions/routes';
        $this->extensionsManager = $extensionsManager ?? new ExtensionsManager();
    }

    /**
     * Generate documentation for all extensions and routes
     *
     * Scans enabled extensions and routes directories and generates documentation
     *
     * @return array List of generated documentation files
     */
    public function generateAll(): array
    {
        $generatedFiles = [];

        // Generate docs only for enabled extensions
        $enabledExtensions = $this->extensionsManager->getEnabledExtensions();

        foreach ($enabledExtensions as $extensionName) {
            $extensionPath = $this->extensionsManager->getExtensionPath($extensionName);
            if ($extensionPath) {
                $routeFile = $extensionPath . '/src/routes.php';

                if (file_exists($routeFile)) {
                    $docFile = $this->generateForExtension($extensionName, $routeFile);
                    if ($docFile) {
                        $generatedFiles[] = $docFile;
                    }
                }
            }
        }

        // Then, generate docs for main routes
        $routeFiles = $this->generateForRoutes();
        $generatedFiles = array_merge($generatedFiles, $routeFiles);

        return $generatedFiles;
    }

    /**
     * Generate documentation for all main route files
     *
     * Scans the routes directory and generates documentation for each route file
     *
     * @return array List of generated documentation files
     */
    public function generateForRoutes(): array
    {
        $generatedFiles = [];

        // Create routes docs directory if it doesn't exist
        if (!is_dir($this->routesOutputPath)) {
            mkdir($this->routesOutputPath, 0755, true);
        }

        // Get all route files in the routes directory
        $routeFiles = glob($this->routesPath . '/*.php');

        foreach ($routeFiles as $routeFile) {
            $routeName = basename($routeFile, '.php');
            $docFile = $this->generateForRouteFile($routeName, $routeFile);
            if ($docFile) {
                $generatedFiles[] = $docFile;
            }
        }

        return $generatedFiles;
    }

    /**
     * Generate documentation for a specific route file
     *
     * @param string $routeName Route file name (without extension)
     * @param string $routeFile Path to route file
     * @param bool $forceGenerate Force generation even if manual file exists
     * @return string|null Path to generated file or null on failure
     */
    public function generateForRouteFile(string $routeName, string $routeFile, bool $forceGenerate = false): ?string
    {
        // Define output file path
        $outputFile = $this->routesOutputPath . '/' . strtolower($routeName) . '.json';

        // Parse routes file to extract doc comments
        $this->parseRouteDocComments($routeFile);

        if (empty($this->routeData)) {
            return null;
        }

        // Generate OpenAPI specification
        $openApiSpec = $this->generateRouteOpenApiSpec($routeName);

        // Write to file
        file_put_contents($outputFile, json_encode($openApiSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $outputFile;
    }

    /**
     * Generate OpenAPI specification for a route file
     *
     * @param string $routeName Route file name
     * @return array OpenAPI specification
     */
    private function generateRouteOpenApiSpec(string $routeName): array
    {
        $paths = [];

        // Format route name for display
        $formattedRouteName = str_replace(['_', '-'], ' ', $routeName);
        $formattedRouteName = ucwords($formattedRouteName);

        // Group routes by tag
        $routesByTag = [];
        foreach ($this->routeData as $route) {
            $tag = $route['tag'];

            if (!isset($routesByTag[$tag])) {
                $routesByTag[$tag] = [];
            }

            $routesByTag[$tag][] = $route;
        }

        // Create tags
        $tags = [];
        foreach (array_keys($routesByTag) as $tag) {
            $tags[] = [
                'name' => $tag,
                'description' => 'Operations related to ' . $tag
            ];
        }

        // Generate paths
        foreach ($this->routeData as $route) {
            $path = $route['path'];
            $method = strtolower($route['method']);

            // Initialize path if it doesn't exist
            if (!isset($paths[$path])) {
                $paths[$path] = [];
            }

            // Create operation object
            $operation = [
                'tags' => [$route['tag']],
                'summary' => $route['summary'],
                'description' => $route['description'],
                'responses' => $route['responses']
            ];

            // Add request body if present
            if (!empty($route['requestBody'])) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => $route['requestBody']
                        ]
                    ]
                ];
            }

            // Add security requirement if authentication is required
            if ($route['requiresAuth']) {
                $operation['security'] = [['BearerAuth' => []]];
            }

            // Add path parameters if any
            if (!empty($route['pathParams'])) {
                $operation['parameters'] = $route['pathParams'];
            }

            // Add operation to path
            $paths[$path][$method] = $operation;
        }

        // Create OpenAPI specification
        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $formattedRouteName . ' Routes',
                'description' => 'API documentation for ' . $formattedRouteName . ' routes',
                'version' => config('app.version_full', '1.0.0')
            ],
            'servers' => [
                [
                    'url' => rtrim(config('app.paths.api_base_url'), '/') . '/' . config('app.api_version'),
                    'description' => 'API Server ' . config('app.api_version')
                ]
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT'
                    ]
                ]
            ],
            'tags' => $tags
        ];
    }

    /**
     * Generate documentation for a specific extension
     *
     * @param string $extensionName Extension name
     * @param string $routeFile Path to routes file
     * @param bool $forceGenerate Force generation even if manual file exists
     * @return string|null Path to generated file or null on failure
     */
    public function generateForExtension(string $extensionName, string $routeFile, bool $forceGenerate = false): ?string
    {
        // Create extension docs directory if it doesn't exist
        $extDocsDir = $this->outputPath . '/' . $extensionName;
        if (!is_dir($extDocsDir)) {
            mkdir($extDocsDir, 0755, true);
        }

        // Define output file path
        $outputFile = $extDocsDir . '/' . strtolower($extensionName) . '.json';

        // Parse routes file to extract doc comments
        $this->parseRouteDocComments($routeFile);

        if (empty($this->routeData)) {
            return null;
        }

        // Generate OpenAPI specification
        $openApiSpec = $this->generateOpenApiSpec($extensionName);

        // Write to file
        file_put_contents($outputFile, json_encode($openApiSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $outputFile;
    }

    /**
     * Parse route file to extract documentation from doc comments
     *
     * @param string $routeFile Path to routes file
     */
    private function parseRouteDocComments(string $routeFile): void
    {
        $this->routeData = [];

        // Get file content
        $content = file_get_contents($routeFile);
        if (!$content) {
            return;
        }

        // Parse doc comment-based documentation
        $this->parseDocCommentBasedDocs($routeFile);
    }

    /**
     * Parse doc comment-based documentation
     *
     * @param string $routeFile Path to the routes file
     * @return bool True if any doc comment-based documentation was found
     */
    private function parseDocCommentBasedDocs(string $routeFile): bool
    {
        // Get file content
        $content = file_get_contents($routeFile);
        if (!$content) {
            return false;
        }

        $foundDocComments = false;

        // Look for doc comments with @route annotation
        $pattern = '/\/\*\*\s*([^*]|\*[^\/])*@route\s+([A-Z]+)\s+([^\s\*]+)([^*]|\*[^\/])*\*\//';
        if (preg_match_all($pattern, $content, $commentMatches)) {
            foreach ($commentMatches[0] as $index => $docComment) {
                // Extract route information
                $httpMethod = strtolower($commentMatches[2][$index]);
                $routePath = $commentMatches[3][$index];

                // Extract basic info
                $summary = $this->extractDocTag($docComment, '@summary');
                $description = $this->extractDocTag($docComment, '@description');
                $tag = $this->extractDocTag($docComment, '@tag');
                $requiresAuth = strtolower($this->extractDocTag($docComment, '@requiresAuth')) === 'true';

                // Extract responses using simplified syntax
                $responses = $this->extractSimplifiedResponses($docComment);

                // Extract request body using simplified syntax
                $requestBody = $this->extractSimplifiedRequestBody($docComment);

                // Extract parameters
                $pathParams = $this->extractSimplifiedParameters($docComment);

                // If no explicit parameters were defined but path contains parameters,
                // extract them from the path
                if (empty($pathParams) && strpos($routePath, '{') !== false) {
                    $pathParams = $this->extractPathParameters($routePath);
                }

                // Add to route data
                $this->routeData[] = [
                    'method' => strtoupper($httpMethod),
                    'path' => $routePath,
                    'summary' => $summary,
                    'description' => $description,
                    'tag' => $tag ?: $this->deriveTagFromPath($routePath),
                    'requiresAuth' => $requiresAuth,
                    'responses' => $responses,
                    'requestBody' => $requestBody,
                    'pathParams' => $pathParams
                ];

                $foundDocComments = true;
            }
        }

        return $foundDocComments;
    }

    /**
     * Extract simplified request body format from doc comment
     * Format: @requestBody field1:type[enum]="description" field2:type="description" {required=field1,field2}
     *
     * @param string $docComment Doc comment to parse
     * @return array|null Request body schema or null if not found
     */
    private function extractSimplifiedRequestBody(string $docComment): ?array
    {
        if (!preg_match('/@requestBody\s+([^\n]+)/', $docComment, $matches)) {
            return null;
        }

        $requestBodyStr = $matches[1];
        $required = [];

        // Extract required fields if specified
        if (preg_match('/\{required=([^}]+)\}/', $requestBodyStr, $reqMatches)) {
            $required = array_map('trim', explode(',', $reqMatches[1]));
            // Remove the required part from the string
            $requestBodyStr = str_replace($reqMatches[0], '', $requestBodyStr);
        }

        // Parse fields
        $properties = [];
        $pattern = '/(\w+):(string|integer|number|boolean|array|object)(?:\[([^\]]*)\])?(?:="([^"]*)")?/';

        preg_match_all($pattern, $requestBodyStr, $fieldMatches, PREG_SET_ORDER);

        foreach ($fieldMatches as $match) {
            $name = $match[1];
            $type = $match[2];
            $enum = isset($match[3]) && !empty($match[3]) ? array_map('trim', explode(',', $match[3])) : null;
            $description = $match[4] ?? '';

            $property = ['type' => $type];

            if ($enum) {
                $property['enum'] = $enum;
            }

            if ($description) {
                $property['description'] = $description;
            }

            $properties[$name] = $property;
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties
        ];

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Extract simplified responses from doc comment
     * Format: @response code contentType "description" {schema}
     *
     * @param string $docComment Doc comment to parse
     * @return array Response definitions
     */
    private function extractSimplifiedResponses(string $docComment): array
    {
        $responses = [];
        $pattern = '/@response\s+(\d+)\s+([\w\/\-+]+)?\s+"([^"]*)"\s*(\{[^}]*\})?/';

        preg_match_all($pattern, $docComment, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $statusCode = $match[1];
            $contentType = !empty($match[2]) ? $match[2] : 'application/json';
            $description = $match[3];
            $schemaStr = isset($match[4]) ? $match[4] : null;

            if ($schemaStr) {
                // Parse schema from the simplified format
                $schema = $this->parseSimplifiedSchema($schemaStr);

                $responses[$statusCode] = [
                    'description' => $description ?: $this->getDefaultResponseDescription($statusCode),
                    'content' => [
                        $contentType => [
                            'schema' => $schema
                        ]
                    ]
                ];
            } else {
                $responses[$statusCode] = [
                    'description' => $description ?: $this->getDefaultResponseDescription($statusCode)
                ];
            }
        }

        // Add default responses if none specified
        if (empty($responses)) {
            $responses = [
                '200' => [
                    'description' => 'Successful operation'
                ],
                '400' => [
                    'description' => 'Bad request'
                ],
                '500' => [
                    'description' => 'Server error'
                ]
            ];
        }

        return $responses;
    }

    /**
     * Parse simplified schema format
     * Format: {field1:type="description", field2:{nestedField:type}}
     *
     * @param string $schemaStr Schema string to parse
     * @return array Parsed schema
     */
    private function parseSimplifiedSchema(string $schemaStr): array
    {
        // Clean up the schema string
        $schemaStr = trim($schemaStr, '{} ');
        $parts = [];
        $start = 0;
        $braceCount = 0;
        $inQuotes = false;

        // Split on commas, but respect nested objects and quoted strings
        for ($i = 0; $i < strlen($schemaStr); $i++) {
            $char = $schemaStr[$i];

            if ($char === '"' && ($i === 0 || $schemaStr[$i - 1] !== '\\')) {
                $inQuotes = !$inQuotes;
            } elseif (!$inQuotes && $char === '{') {
                $braceCount++;
            } elseif (!$inQuotes && $char === '}') {
                $braceCount--;
            } elseif (!$inQuotes && $char === ',' && $braceCount === 0) {
                $parts[] = substr($schemaStr, $start, $i - $start);
                $start = $i + 1;
            }
        }

        // Add the last part
        if ($start < strlen($schemaStr)) {
            $parts[] = substr($schemaStr, $start);
        }

        $properties = [];
        $type = 'object';

        // Process simple array notation
        if (strpos($schemaStr, '[') === 0 && substr($schemaStr, -1) === ']') {
            $type = 'array';
            $itemsSchema = $this->parseSimplifiedSchema(substr($schemaStr, 1, -1));
            return [
                'type' => 'array',
                'items' => $itemsSchema
            ];
        }

        // Process each part as a property
        foreach ($parts as $part) {
            $part = trim($part);

            // Match field with type and optional description
            if (
                preg_match(
                    '/(\w+):(string|integer|number|boolean|array|object)(?:\[([^\]]*)\])?(?:="([^"]*)")?/',
                    $part,
                    $match
                )
            ) {
                $name = $match[1];
                $propType = $match[2];
                $description = isset($match[4]) ? $match[4] : (isset($match[3]) ? $match[3] : '');

                $property = ['type' => $propType];

                if (isset($match[3]) && preg_match('/^[^"=]/', $match[3])) {
                    // This is an enum
                    $property['enum'] = array_map('trim', explode(',', $match[3]));
                }

                if ($description) {
                    $property['description'] = $description;
                }

                $properties[$name] = $property;
            } elseif (preg_match('/(\w+):(\{[^}]+\})/', $part, $match)) {
                $name = $match[1];
                $nestedSchema = $this->parseSimplifiedSchema($match[2]);
                $properties[$name] = $nestedSchema;
            } elseif (preg_match('/(\w+):(\[[^\]]+\])/', $part, $match)) {
                $name = $match[1];
                $itemsSchema = $this->parseSimplifiedSchema(substr($match[2], 1, -1));
                $properties[$name] = [
                    'type' => 'array',
                    'items' => $itemsSchema
                ];
            }
        }

        return [
            'type' => $type,
            'properties' => $properties
        ];
    }

    /**
     * Extract simplified parameters from doc comment
     * Format: @param name location type required "description"
     *
     * @param string $docComment Doc comment to parse
     * @return array Parameter definitions
     */
    private function extractSimplifiedParameters(string $docComment): array
    {
        $params = [];
        $pattern = '/@param\s+(\w+)\s+(path|query|header|cookie)\s+(string|integer|number|boolean|array|object)'
            . '\s+(true|false)\s+"([^"]*)"/';

        preg_match_all($pattern, $docComment, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $params[] = [
                'name' => $match[1],
                'in' => $match[2],
                'required' => $match[4] === 'true',
                'description' => $match[5],
                'schema' => ['type' => $match[3]]
            ];
        }

        return $params;
    }

    /**
     * Derive tag from path
     *
     * @param string $path API path
     * @return string Tag name
     */
    private function deriveTagFromPath(string $path): string
    {
        $pathParts = explode('/', trim($path, '/'));
        return ucfirst($pathParts[0] ?? 'default');
    }

    /**
     * Extract a specific tag value from a doc comment
     *
     * @param string $docComment Doc comment to parse
     * @param string $tagName Tag name to extract
     * @return string Tag value or empty string if not found
     */
    private function extractDocTag(string $docComment, string $tagName): string
    {
        $pattern = '/' . preg_quote($tagName) . '\s+([^\r\n]+)/';
        if (preg_match($pattern, $docComment, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    /**
     * Extract path parameters from a route path
     *
     * @param string $path Route path
     * @return array Path parameters
     */
    private function extractPathParameters(string $path): array
    {
        $params = [];
        if (preg_match_all('/\{([^}]+)\}/', $path, $matches)) {
            foreach ($matches[1] as $param) {
                $params[] = [
                    'name' => $param,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string']
                ];
            }
        }
        return $params;
    }

    /**
     * Get standard description for HTTP status code
     *
     * @param string $statusCode HTTP status code
     * @return string Description
     */
    private function getDefaultResponseDescription(string $statusCode): string
    {
        $descriptions = [
            '200' => 'OK',
            '201' => 'Created',
            '204' => 'No Content',
            '400' => 'Bad Request',
            '401' => 'Unauthorized',
            '403' => 'Forbidden',
            '404' => 'Not Found',
            '500' => 'Internal Server Error'
        ];

        return $descriptions[$statusCode] ?? 'Response';
    }

    /**
     * Generate OpenAPI specification from parsed route data
     *
     * @param string $extensionName Extension name
     * @return array OpenAPI specification
     */
    private function generateOpenApiSpec(string $extensionName): array
    {
        $paths = [];

        // Format extension name for display
        $formattedExtName = str_replace(['_', '-'], ' ', $extensionName);
        $formattedExtName = ucwords($formattedExtName);

        // Group routes by tag
        $routesByTag = [];
        foreach ($this->routeData as $route) {
            $tag = $route['tag'];

            if (!isset($routesByTag[$tag])) {
                $routesByTag[$tag] = [];
            }

            $routesByTag[$tag][] = $route;
        }

        // Create tags
        $tags = [];
        foreach (array_keys($routesByTag) as $tag) {
            $tags[] = [
                'name' => $tag,
                'description' => 'Operations related to ' . $tag
            ];
        }

        // Generate paths
        foreach ($this->routeData as $route) {
            $path = $route['path'];
            $method = strtolower($route['method']);

            // Initialize path if it doesn't exist
            if (!isset($paths[$path])) {
                $paths[$path] = [];
            }

            // Create operation object
            $operation = [
                'tags' => [$route['tag']],
                'summary' => $route['summary'],
                'description' => $route['description'],
                'responses' => $route['responses']
            ];

            // Add request body if present
            if (!empty($route['requestBody'])) {
                $operation['requestBody'] = [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => $route['requestBody']
                        ]
                    ]
                ];
            }

            // Add security requirement if authentication is required
            if ($route['requiresAuth']) {
                $operation['security'] = [['BearerAuth' => []]];
            }

            // Add path parameters if any
            if (!empty($route['pathParams'])) {
                $operation['parameters'] = $route['pathParams'];
            }

            // Add operation to path
            $paths[$path][$method] = $operation;
        }

        // Create OpenAPI specification
        return [
            'openapi' => '3.0.0',
            'info' => [
                'title' => $formattedExtName . ' API',
                'description' => 'API documentation for ' . $formattedExtName . ' extension',
                'version' => config('app.version_full', '1.0.0')
            ],
            'servers' => [
                [
                    'url' => rtrim(config('app.paths.api_base_url'), '/') . '/' . config('app.api_version'),
                    'description' => 'API Server ' . config('app.api_version')
                ]
            ],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT'
                    ]
                ]
            ],
            'tags' => $tags
        ];
    }
}
