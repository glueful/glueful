<?php
declare(strict_types=1);

namespace Glueful;

/**
 * Extension Documentation Generator
 * 
 * Dynamically generates OpenAPI documentation for extension routes by:
 * - Scanning extension directories for route files
 * - Parsing route definitions to extract endpoint information
 * - Generating OpenAPI specifications automatically
 * - Creating necessary directory structure
 */
class ExtensionDocGenerator
{
    /** @var string Base path to extensions directory */
    private string $extensionsPath;
    
    /** @var string Output directory for generated documentation */
    private string $outputPath;
    
    /** @var array Processed route information */
    private array $routeData = [];
    
    /**
     * Constructor
     * 
     * @param string|null $extensionsPath Custom path to extensions directory
     * @param string|null $outputPath Custom output path for documentation
     */
    public function __construct(?string $extensionsPath = null, ?string $outputPath = null)
    {
        $this->extensionsPath = $extensionsPath ?? dirname(__DIR__) . '/extensions';
        $this->outputPath = $outputPath ?? dirname(__DIR__) . '/docs/api-doc-json-definitions/extensions';
    }
    
    /**
     * Generate documentation for all extensions
     * 
     * Scans all extensions and generates documentation for those with route files
     * 
     * @return array List of generated documentation files
     */
    public function generateAll(): array
    {
        $generatedFiles = [];
        
        // Get all extension directories
        $extensionDirs = array_filter(glob($this->extensionsPath . '/*'), 'is_dir');
        
        foreach ($extensionDirs as $extDir) {
            $extName = basename($extDir);
            $routeFile = $extDir . '/routes.php';
            
            if (file_exists($routeFile)) {
                $docFile = $this->generateForExtension($extName, $routeFile);
                if ($docFile) {
                    $generatedFiles[] = $docFile;
                }
            }
        }
        
        return $generatedFiles;
    }
    
    /**
     * Generate documentation for a specific extension
     * 
     * @param string $extensionName Extension name
     * @param string $routeFile Path to routes file
     * @return string|null Path to generated file or null on failure
     */
    public function generateForExtension(string $extensionName, string $routeFile): ?string
    {
        // Parse routes file to extract route information
        $this->parseRouteFile($routeFile);
        
        if (empty($this->routeData)) {
            return null;
        }
        
        // Create extension docs directory if it doesn't exist
        $extDocsDir = $this->outputPath . '/' . $extensionName;
        if (!is_dir($extDocsDir)) {
            mkdir($extDocsDir, 0755, true);
        }
        
        // Generate OpenAPI specification
        $openApiSpec = $this->generateOpenApiSpec($extensionName);
        
        // Write to file
        $outputFile = $extDocsDir . '/' . strtolower($extensionName) . '.json';
        file_put_contents($outputFile, json_encode($openApiSpec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        
        return $outputFile;
    }
    
    /**
     * Parse route file to extract route information
     * 
     * @param string $routeFile Path to routes file
     */
    private function parseRouteFile(string $routeFile): void
    {
        $this->routeData = [];
        
        // Get file content
        $content = file_get_contents($routeFile);
        if (!$content) {
            return;
        }
        
        // Extract PHPDoc comments for route groups
        preg_match_all('/\/\*\*(.*?)\*\//s', $content, $docComments);
        $groupDescription = '';
        if (!empty($docComments[1])) {
            // Use the first doc comment as the group description
            $groupDescription = $this->parseDocComment($docComments[1][0]);
        }
        
        // Extract route groups
        preg_match_all('/Router::group\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\(\s*\)\s*{(.*?)}\s*\)\s*;/s', $content, $groups);
        
        if (empty($groups[1])) {
            return;
        }
        
        // Process each route group
        foreach ($groups[1] as $index => $groupPrefix) {
            $groupContent = $groups[2][$index];
            
            // Extract routes within the group
            preg_match_all('/Router::(get|post|put|delete|patch)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\(\s*.*?\)\s*{(.*?)}\s*,?\s*(?:requiresAuth\s*:\s*(true|false))?/s', $groupContent, $routes);
            
            if (empty($routes[1])) {
                continue;
            }
            
            // Process each route
            for ($i = 0; $i < count($routes[1]); $i++) {
                $method = strtoupper($routes[1][$i]);
                $path = $groupPrefix . $routes[2][$i];
                $routeBody = $routes[3][$i];
                $requiresAuth = isset($routes[4][$i]) && $routes[4][$i] === 'true';
                
                // Extract route description from comments
                $routeDescription = '';
                if (preg_match('/\/\/(.*?)$/m', $routeBody, $routeComment)) {
                    $routeDescription = trim($routeComment[1]);
                }
                
                // Extract response structure
                $responseStructure = $this->extractResponseStructure($routeBody);
                
                // Extract path parameters
                $pathParams = [];
                if (preg_match_all('/\{([^}]+)\}/', $path, $matches)) {
                    foreach ($matches[1] as $param) {
                        $pathParams[] = [
                            'name' => $param,
                            'in' => 'path',
                            'required' => true,
                            'schema' => ['type' => 'string']
                        ];
                    }
                }
                
                // Store route information
                $this->routeData[] = [
                    'method' => $method,
                    'path' => $path,
                    'description' => $routeDescription,
                    'requiresAuth' => $requiresAuth,
                    'pathParams' => $pathParams,
                    'responseStructure' => $responseStructure
                ];
            }
        }
    }
    
    /**
     * Extract response structure from route handler body
     * 
     * @param string $routeBody Route handler function body
     * @return array Response structure
     */
    private function extractResponseStructure(string $routeBody): array
    {
        $structure = [
            'success' => true,
            'data' => []
        ];
        
        // Check for Response::ok calls
        if (preg_match('/Response::ok\s*\((.*?),/s', $routeBody, $match)) {
            // Check for database queries to determine response shape
            if (preg_match('/select\s*\(\s*[\'"][^\'"]+[\'"]\s*,\s*\[(.*?)\]\s*\)/s', $routeBody, $dbMatch)) {
                $fields = explode(',', $dbMatch[1]);
                foreach ($fields as $field) {
                    if (preg_match('/[\'"]([^\'"]+)[\'"]/', $field, $nameMatch)) {
                        $structure['data'][$nameMatch[1]] = 'string';
                    }
                }
            }
        }
        
        return $structure;
    }
    
    /**
     * Parse PHPDoc comment to extract description
     * 
     * @param string $docComment PHPDoc comment
     * @return string Extracted description
     */
    private function parseDocComment(string $docComment): string
    {
        // Remove asterisks and leading whitespace
        $lines = explode("\n", $docComment);
        $description = '';
        
        foreach ($lines as $line) {
            $line = preg_replace('/^\s*\*\s*/', '', $line);
            if (strpos($line, '@') === 0) {
                continue; // Skip annotation lines
            }
            $description .= $line . "\n";
        }
        
        return trim($description);
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
        $tags = [];
        
        // Group routes by first path segment for tagging
        $routesByTag = [];
        foreach ($this->routeData as $route) {
            $pathSegments = explode('/', trim($route['path'], '/'));
            $tag = $pathSegments[0] ?? 'default';
            
            if (!isset($routesByTag[$tag])) {
                $routesByTag[$tag] = [];
                $tags[] = [
                    'name' => ucfirst($tag),
                    'description' => "Operations related to " . ucfirst($tag)
                ];
            }
            
            $routesByTag[$tag][] = $route;
        }
        
        // Generate paths
        foreach ($this->routeData as $route) {
            $pathSegments = explode('/', trim($route['path'], '/'));
            $tag = $pathSegments[0] ?? 'default';
            
            $path = $route['path'];
            $method = strtolower($route['method']);
            
            // Initialize path if it doesn't exist
            if (!isset($paths[$path])) {
                $paths[$path] = [];
            }
            
            // Create operation object
            $operation = [
                'tags' => [ucfirst($tag)],
                'summary' => $route['description'] ?: ucfirst($method) . ' ' . $path,
                'description' => $route['description'] ?: 'Endpoint for ' . $path,
                'responses' => [
                    '200' => [
                        'description' => 'Successful response',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'success' => [
                                            'type' => 'boolean',
                                            'example' => true
                                        ],
                                        'message' => [
                                            'type' => 'string'
                                        ],
                                        'data' => [
                                            'type' => 'object',
                                            'properties' => $this->mapResponseStructure($route['responseStructure']['data'] ?? [])
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    '400' => [
                        'description' => 'Bad request'
                    ],
                    '401' => [
                        'description' => 'Unauthorized'
                    ],
                    '500' => [
                        'description' => 'Server error'
                    ]
                ]
            ];
            
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
                'title' => $extensionName . ' API',
                'description' => 'API documentation for ' . $extensionName . ' extension',
                'version' => '1.0.0'
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
     * Map extracted data structure to OpenAPI schema properties
     * 
     * @param array $data Extracted data structure
     * @return array OpenAPI schema properties
     */
    private function mapResponseStructure(array $data): array
    {
        $properties = [];
        
        foreach ($data as $field => $type) {
            $properties[$field] = [
                'type' => $this->mapDataType($type)
            ];
        }
        
        return $properties;
    }
    
    /**
     * Map PHP/database data type to OpenAPI type
     * 
     * @param string $type PHP/database type
     * @return string OpenAPI type
     */
    private function mapDataType(string $type): string
    {
        switch ($type) {
            case 'int':
            case 'integer':
                return 'integer';
            case 'float':
            case 'double':
                return 'number';
            case 'bool':
            case 'boolean':
                return 'boolean';
            case 'array':
                return 'array';
            default:
                return 'string';
        }
    }
}