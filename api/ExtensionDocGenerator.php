<?php
declare(strict_types=1);

namespace Glueful;

/**
 * Extension Documentation Generator
 * 
 * Dynamically generates OpenAPI documentation for extension routes by:
 * - Scanning extension directories for route files
 * - Checking for custom documentation files
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
        // Create extension docs directory if it doesn't exist
        $extDocsDir = $this->outputPath . '/' . $extensionName;
        if (!is_dir($extDocsDir)) {
            mkdir($extDocsDir, 0755, true);
        }
        
        // Define output file path
        $outputFile = $extDocsDir . '/' . strtolower($extensionName) . '.json';
        
        // Check for manual definition file
        $manualDefFile = $extDocsDir . '/social_login.json';
        if (file_exists($manualDefFile)) {
            // Copy the manually created definition file
            echo "Found manual definition file for {$extensionName}\n";
            copy($manualDefFile, $outputFile);
            return $outputFile;
        }
        
        // Parse routes file to extract route information
        $this->parseRouteFile($routeFile);
        
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
            
            // First, check for Router::match calls which handle multiple methods
            $this->parseMatchRoutes($groupContent, $groupPrefix);
            
            // Then extract standard routes within the group
            preg_match_all('/Router::(get|post|put|delete|patch)\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\(\s*.*?\)\s*{(.*?)(?:}\s*,?\s*(?:requiresAuth\s*:\s*(true|false))?\s*\)\s*;)/s', $groupContent, $routes);
            
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
                $routeDescription = $this->extractRouteDescription($routeBody);
                
                // Extract request body schema
                $requestBody = $this->extractRequestBody($routeBody);
                
                // Extract response structure
                $responseStructure = $this->extractResponseStructure($routeBody);
                
                // Extract path parameters
                $pathParams = $this->extractPathParameters($path);
                
                // Store route information
                $this->routeData[] = [
                    'method' => $method,
                    'path' => $path,
                    'description' => $routeDescription,
                    'requestBody' => $requestBody,
                    'requiresAuth' => $requiresAuth,
                    'pathParams' => $pathParams,
                    'responseStructure' => $responseStructure
                ];
            }
        }
    }
    
    /**
     * Parse Router::match routes that handle multiple HTTP methods
     * 
     * @param string $groupContent Content of the route group
     * @param string $groupPrefix Group URL prefix
     */
    private function parseMatchRoutes(string $groupContent, string $groupPrefix): void
    {
        // Match Router::match(['GET', 'POST'], '/path', function...)
        preg_match_all('/Router::match\s*\(\s*\[(.*?)\]\s*,\s*[\'"]([^\'"]+)[\'"]\s*,\s*function\s*\(\s*.*?\)\s*{(.*?)(?:}\s*,?\s*(?:requiresAuth\s*:\s*(true|false))?\s*\)\s*;)/s', $groupContent, $matches);
        
        if (empty($matches[1])) {
            return;
        }
        
        for ($i = 0; $i < count($matches[1]); $i++) {
            $methodsStr = $matches[1][$i];
            $path = $groupPrefix . $matches[2][$i];
            $routeBody = $matches[3][$i];
            $requiresAuth = isset($matches[4][$i]) && $matches[4][$i] === 'true';
            
            // Extract HTTP methods from the array
            preg_match_all('/[\'"]([A-Z]+)[\'"]/', $methodsStr, $methodMatches);
            $methods = $methodMatches[1];
            
            if (empty($methods)) {
                continue;
            }
            
            // Extract route description from comments
            $routeDescription = $this->extractRouteDescription($routeBody);
            
            // Extract condition checks that might distinguish between methods
            $methodHandlers = $this->extractMethodHandlers($routeBody, $methods);
            
            // For each method, create a route entry
            foreach ($methods as $method) {
                // Extract request body schema - might depend on the method
                $requestBody = $this->extractRequestBody($methodHandlers[$method] ?? $routeBody, $method);
                
                // Extract response structure
                $responseStructure = $this->extractResponseStructure($methodHandlers[$method] ?? $routeBody);
                
                // Extract path parameters
                $pathParams = $this->extractPathParameters($path);
                
                // Store route information
                $this->routeData[] = [
                    'method' => $method,
                    'path' => $path,
                    'description' => $routeDescription,
                    'requestBody' => $requestBody,
                    'requiresAuth' => $requiresAuth,
                    'pathParams' => $pathParams,
                    'responseStructure' => $responseStructure
                ];
            }
        }
    }
    
    /**
     * Extract method-specific handlers from a multi-method route
     * 
     * @param string $routeBody Route handler function body
     * @param array $methods HTTP methods supported by the route
     * @return array Associative array mapping methods to their handler code
     */
    private function extractMethodHandlers(string $routeBody, array $methods): array
    {
        $handlers = [];
        
        // Look for method checks like if ($request->getMethod() === 'POST')
        preg_match_all('/if\s*\(\s*\$request->(?:getMethod|request->method)\s*(?:==|===)\s*[\'"]([A-Z]+)[\'"]\s*\)\s*{(.*?)}/s', $routeBody, $matches);
        
        if (!empty($matches[1])) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $method = $matches[1][$i];
                $handlers[$method] = $matches[2][$i];
            }
        }
        
        // For methods without explicit handlers, use the full body
        foreach ($methods as $method) {
            if (!isset($handlers[$method])) {
                $handlers[$method] = $routeBody;
            }
        }
        
        return $handlers;
    }
    
    /**
     * Extract route description from the handler body or comments
     * 
     * @param string $routeBody Route handler function body
     * @return string Extracted description
     */
    private function extractRouteDescription(string $routeBody): string
    {
        // Look for inline comments
        if (preg_match('/\/\/\s*(.*?)$/m', $routeBody, $routeComment)) {
            return trim($routeComment[1]);
        }
        
        // Look for multiline comments
        if (preg_match('/\/\*\s*(.*?)\s*\*\//s', $routeBody, $routeComment)) {
            return trim($routeComment[1]);
        }
        
        // Look for log messages or response messages that might describe the route
        if (preg_match('/Response::ok\s*\(\s*.*?,\s*[\'"]([^\'"]+)[\'"]\s*\)/s', $routeBody, $responseMessage)) {
            return trim($responseMessage[1]);
        }
        
        return '';
    }
    
    /**
     * Extract request body schema from route handler
     * 
     * @param string $routeBody Route handler function body
     * @param string $method HTTP method
     * @return array|null Request body schema or null if not needed
     */
    private function extractRequestBody(string $routeBody, string $method = ''): ?array
    {
        // Only POST, PUT, PATCH typically have request bodies
        if ($method && !in_array($method, ['POST', 'PUT', 'PATCH'])) {
            return null;
        }
        
        $schema = null;
        
        // Check for JSON deserialization
        if (preg_match('/json_decode\s*\(\s*\$request->getContent\(\)\s*,\s*true\s*\)/s', $routeBody)) {
            $schema = [
                'type' => 'object',
                'properties' => []
            ];
            
            // Try to extract validation or property access to determine schema
            preg_match_all('/\$requestData\s*\[\s*[\'"]([^\'"]+)[\'"]\s*\]/', $routeBody, $propMatches);
            if (!empty($propMatches[1])) {
                foreach ($propMatches[1] as $prop) {
                    $schema['properties'][$prop] = ['type' => 'string'];
                    
                    // Check if property is required
                    if (preg_match('/if\s*\(\s*empty\s*\(\s*\$requestData\s*\[\s*[\'"]' . preg_quote($prop, '/') . '[\'"]\s*\]\s*\)\s*\)/', $routeBody)) {
                        $schema['required'] = $schema['required'] ?? [];
                        $schema['required'][] = $prop;
                    }
                }
            }
        }
        
        return $schema;
    }
    
    /**
     * Extract path parameters from route path
     * 
     * @param string $path Route path
     * @return array Path parameters
     */
    private function extractPathParameters(string $path): array
    {
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
        return $pathParams;
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
        if (preg_match_all('/Response::ok\s*\(\s*(.*?)\s*(?:,|\))/s', $routeBody, $matches)) {
            foreach ($matches[1] as $match) {
                // Check if we're returning an array structure we can parse
                if (preg_match('/\[(.*?)\]/', $match, $arrayMatch)) {
                    $arrayContent = $arrayMatch[1];
                    preg_match_all('/[\'"]([^\'"]+)[\'"]\s*=>\s*/', $arrayContent, $keyMatches);
                    
                    if (!empty($keyMatches[1])) {
                        foreach ($keyMatches[1] as $key) {
                            $structure['data'][$key] = 'string';
                        }
                    }
                }
                
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
                                        'status' => [
                                            'type' => 'string',
                                            'example' => 'success'
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
    
    /**
     * Main entry point for CLI usage
     * 
     * @param array $args Command line arguments
     * @return void
     */
    public static function main(array $args): void
    {
        if (count($args) < 2) {
            echo "Usage: php ExtensionDocGenerator.php <extension_name>\n";
            echo "       php ExtensionDocGenerator.php all\n";
            return;
        }
        
        $generator = new self();
        
        if ($args[1] === 'all') {
            echo "Generating documentation for all extensions...\n";
            $files = $generator->generateAll();
            echo "Generated " . count($files) . " documentation files:\n";
            foreach ($files as $file) {
                echo "- $file\n";
            }
        } else {
            $extensionName = $args[1];
            $routeFile = $generator->extensionsPath . '/' . $extensionName . '/routes.php';
            
            if (!file_exists($routeFile)) {
                echo "Error: Routes file not found for extension '$extensionName'\n";
                return;
            }
            
            echo "Generating documentation for extension '$extensionName'...\n";
            $file = $generator->generateForExtension($extensionName, $routeFile);
            
            if ($file) {
                echo "Documentation generated: $file\n";
            } else {
                echo "Failed to generate documentation for extension '$extensionName'\n";
            }
        }
    }
}

// Run the generator if invoked directly
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    ExtensionDocGenerator::main($argv);
}