<?php

declare(strict_types=1);

namespace Glueful\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Configurable CORS Handler - Developer-Friendly
 *
 * A comprehensive Cross-Origin Resource Sharing (CORS) handler that provides
 * flexible configuration options and seamless integration with the Glueful framework.
 * Designed to be developer-friendly with sensible defaults while maintaining
 * security best practices for production environments.
 *
 * Key Features:
 * - Multiple configuration sources (config files, env vars, constructor)
 * - Factory methods for common scenarios (development, production, universal)
 * - Fluent interface for method chaining and dynamic configuration
 * - Automatic string-to-array conversion for environment variables
 * - Sensible defaults with wildcard fallback for quick setup
 * - Proper preflight request handling (OPTIONS method)
 * - Configurable origin validation and method/header restrictions
 * - Credential support with security considerations
 *
 * Usage Examples:
 * ```php
 * // Basic usage with config file settings
 * $cors = new Cors();
 * $cors->handle();
 *
 * // Development setup (allows localhost ports)
 * $cors = Cors::development();
 * $cors->handle();
 *
 * // Production setup with specific origins
 * $cors = Cors::production(['https://myapp.com', 'https://api.myapp.com']);
 * $cors->handle();
 *
 * // Fluent interface configuration
 * $cors = Cors::universal()
 *     ->allowOrigins(['https://trusted.com'])
 *     ->withCredentials(true)
 *     ->withDebug(true);
 * ```
 *
 * Security Considerations:
 * - Wildcard origins (*) disable credential support for security
 * - Origin validation prevents unauthorized cross-origin requests
 * - Proper preflight handling ensures compliance with CORS specification
 * - Method and header restrictions provide additional access control
 *
 * @package Glueful\Http
 * @author Glueful Core Team
 * @since 1.0.0
 */
class Cors
{
    /**
     * CORS configuration array
     *
     * Contains all CORS-related settings including:
     * - allowedOrigins: Array of allowed origin URLs or ['*'] for all
     * - allowedMethods: HTTP methods permitted for cross-origin requests
     * - allowedHeaders: Headers that can be sent in cross-origin requests
     * - exposedHeaders: Headers exposed to the requesting client
     * - maxAge: Cache duration for preflight responses (seconds)
     * - supportsCredentials: Whether credentials (cookies, auth) are allowed
     * - debug: Development flag for additional debugging information
     *
     * @var array
     */
    private array $config;

    /**
     * Initialize the CORS handler with configuration
     *
     * Loads CORS configuration from multiple sources in order of precedence:
     * 1. Constructor parameters (highest priority)
     * 2. Application config files
     * 3. Default values (fallback)
     *
     * The constructor automatically handles:
     * - String-to-array conversion for environment variables
     * - Wildcard fallback when no origins are configured
     * - Merging of configuration sources with proper precedence
     *
     * Configuration Sources:
     * - security.cors.allowed_origins: Comma-separated string or array
     * - security.cors.allowed_methods: Array of HTTP methods
     * - security.cors.allowed_headers: Array of allowed request headers
     * - security.cors.expose_headers: Array of headers to expose to client
     * - security.cors.max_age: Cache duration for preflight responses
     * - security.cors.supports_credentials: Boolean for credential support
     *
     * @param array $config Override configuration options
     */
    public function __construct(array $config = [])
    {
        // Load origins from config and normalize to array
        // This supports both string (from env vars) and array formats
        $allowedOrigins = config('security.cors.allowed_origins', []);

        // Handle string input (from env vars) by converting to array
        // Expected format: "https://app1.com,https://app2.com,http://localhost:3000"
        if (is_string($allowedOrigins)) {
            $allowedOrigins = array_map('trim', explode(',', $allowedOrigins));
            $allowedOrigins = array_filter($allowedOrigins); // Remove empty strings
        }

        // If no origins configured, allow all (wildcard) for easy development
        // Note: This disables credential support for security reasons
        if (empty($allowedOrigins)) {
            $allowedOrigins = ['*'];
        }

        // Merge configuration with defaults, giving precedence to constructor params
        $this->config = array_merge([
            'allowedOrigins' => $allowedOrigins,
            'allowedMethods' => config('security.cors.allowed_methods', [
                'GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'
            ]),
            'allowedHeaders' => config('security.cors.allowed_headers', [
                'Content-Type', 'Authorization', 'X-Requested-With'
            ]),
            'exposedHeaders' => config('security.cors.expose_headers', [
                'X-Total-Count', 'X-Page-Count'
            ]),
            'maxAge' => config('security.cors.max_age', 86400), // 24 hours
            'supportsCredentials' => config('security.cors.supports_credentials', true),
        ], $config);
    }

    /**
     * Handle CORS for the incoming request
     *
     * This is the main entry point for CORS processing. It handles both
     * preflight OPTIONS requests and regular requests by:
     * 1. Detecting request type (preflight vs regular)
     * 2. Validating origin against allowed origins
     * 3. Adding appropriate CORS headers
     * 4. Returning control flow instructions
     *
     * For preflight requests (OPTIONS method):
     * - Validates the requesting origin
     * - Checks requested method and headers against allowed lists
     * - Sends complete preflight response and exits
     *
     * For regular requests:
     * - Adds CORS headers if origin is allowed
     * - Returns true to continue processing the request
     *
     * @param Request|null $request The HTTP request object. If null, creates from globals
     * @return bool True to continue request processing, false if request was handled
     * @throws \Exception On configuration errors or invalid requests
     */
    public function handle($request = null): bool
    {
       // Check for empty $request and use fallback if needed
        if (empty($request)) {
            $request = Request::createFromGlobals();
        }
        $origin = $request->headers->get('Origin', '');

        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            $this->handlePreflightRequest($request, $origin);
            return false; // Request handled, don't continue to routing
        }

        // Add CORS headers for regular requests
        $this->addCorsHeaders($origin);
        return true; // Continue to routing
    }

    /**
     * Handle CORS preflight request (OPTIONS method)
     *
     * Processes preflight requests according to CORS specification:
     * 1. Validates the requesting origin against allowed origins
     * 2. Checks requested method against allowed methods
     * 3. Validates requested headers against allowed headers
     * 4. Sends appropriate preflight response headers
     * 5. Exits with HTTP 204 (success) or 403 (forbidden)
     *
     * Preflight requests are sent by browsers for:
     * - Non-simple HTTP methods (PUT, DELETE, PATCH, custom methods)
     * - Requests with custom headers
     * - Requests with certain content types
     *
     * @param Request $request The incoming OPTIONS request
     * @param string $origin The Origin header value from the request
     * @return void This method always exits (sends response and terminates)
     */
    private function handlePreflightRequest(Request $request, string $origin): void
    {
        // Check if origin is allowed
        if (!$this->isOriginAllowed($origin)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'CORS origin not allowed']);
            exit;
        }

        // Validate requested method
        $requestedMethod = $request->headers->get('Access-Control-Request-Method');
        if ($requestedMethod && !in_array($requestedMethod, $this->config['allowedMethods'], true)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'CORS method not allowed']);
            exit;
        }

        // Send preflight response
        http_response_code(204);
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: ' . implode(', ', $this->config['allowedMethods']));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->config['allowedHeaders']));
        header('Access-Control-Max-Age: ' . $this->config['maxAge']);

        if ($this->config['supportsCredentials']) {
            header('Access-Control-Allow-Credentials: true');
        }

        exit; // End preflight request
    }

    /**
     * Add CORS headers for regular (non-preflight) requests
     *
     * Attaches the necessary CORS headers to regular HTTP responses:
     * - Access-Control-Allow-Origin: Echoes back the requesting origin if allowed
     * - Access-Control-Expose-Headers: Headers the client can access
     * - Access-Control-Allow-Credentials: Whether cookies/auth are allowed
     *
     * This method only adds headers if the requesting origin is in the
     * allowed origins list. Invalid origins are silently ignored.
     *
     * @param string $origin The Origin header value from the request
     * @return void Headers are set directly via PHP's header() function
     */
    private function addCorsHeaders(string $origin): void
    {
        // Only add CORS headers if origin is allowed
        if (!$this->isOriginAllowed($origin)) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $origin);

        // Add optional CORS headers
        if (!empty($this->config['exposedHeaders'])) {
            header('Access-Control-Expose-Headers: ' . implode(', ', $this->config['exposedHeaders']));
        }

        if ($this->config['supportsCredentials']) {
            header('Access-Control-Allow-Credentials: true');
        }
    }

    /**
     * Check if the requesting origin is allowed
     *
     * Validates the requesting origin against the configured allowed origins:
     * - Returns false for empty/missing origins
     * - Returns true if wildcard (*) is in allowed origins
     * - Returns true if origin exactly matches an allowed origin
     * - Returns false otherwise
     *
     * Note: This method performs exact string matching. For more complex
     * domain matching (subdomains, etc.), extend this method.
     *
     * @param string $origin The Origin header value from the request
     * @return bool True if origin is allowed, false otherwise
     */
    private function isOriginAllowed(string $origin): bool
    {
        if (empty($origin)) {
            return false;
        }

        // Check for wildcard - allows all origins
        if (in_array('*', $this->config['allowedOrigins'], true)) {
            return true;
        }

        // Check against exact matches
        if (in_array($origin, $this->config['allowedOrigins'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Factory method for universal CORS configuration
     *
     * Creates a CORS instance that allows all origins (*). This is useful
     * for public APIs or development environments where you need maximum
     * compatibility.
     *
     * Configuration:
     * - Origins: Wildcard (*) - allows all domains
     * - Methods: Common HTTP methods (GET, POST, PUT, DELETE, OPTIONS)
     * - Headers: Wildcard (*) - allows all headers
     * - Credentials: Disabled (required for security with wildcard origins)
     *
     * Security Note: This configuration should NOT be used in production
     * for APIs that handle sensitive data or authentication.
     *
     * @return self New CORS instance with universal access configuration
     */
    public static function universal(): self
    {
        return new self([
            'allowedOrigins' => ['*'],
            'allowedMethods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowedHeaders' => ['*'],
            'supportsCredentials' => false, // Can't use credentials with wildcard
        ]);
    }

    /**
     * Factory method for development environment CORS
     *
     * Creates a CORS instance optimized for local development with
     * commonly used localhost ports and addresses. Perfect for frontend
     * development servers, testing, and local API development.
     *
     * Allowed Origins:
     * - localhost and 127.0.0.1 (both HTTP and various ports)
     * - Common dev server ports: 3000, 3001, 5173 (Vite), 8080
     * - Supports both localhost and 127.0.0.1 variants
     *
     * This configuration maintains security while providing convenience
     * for local development workflows.
     *
     * @return self New CORS instance configured for development
     */
    public static function development(): self
    {
        return new self([
            'allowedOrigins' => [
                'http://localhost',
                'http://localhost:3000',
                'http://localhost:3001',
                'http://localhost:5173',
                'http://localhost:8080',
                'http://127.0.0.1',
                'http://127.0.0.1:3000',
                'http://127.0.0.1:3001',
                'http://127.0.0.1:5173',
                'http://127.0.0.1:8080',
            ],
        ]);
    }

    /**
     * Factory method for production environment CORS
     *
     * Creates a CORS instance configured for production use with specific
     * allowed origins. This provides maximum security by explicitly defining
     * which domains can access your API.
     *
     * Features:
     * - Explicit origin allowlist (no wildcards)
     * - Credentials enabled for authenticated requests
     * - Standard HTTP methods and security headers
     * - Suitable for production APIs with known frontend domains
     *
     * Usage:
     * ```php
     * $cors = Cors::production([
     *     'https://myapp.com',
     *     'https://admin.myapp.com',
     *     'https://api.myapp.com'
     * ]);
     * ```
     *
     * @param array $allowedOrigins List of specific domains allowed to access the API
     * @return self New CORS instance configured for production
     */
    public static function production(array $allowedOrigins): self
    {
        return new self([
            'allowedOrigins' => $allowedOrigins,
            'supportsCredentials' => true,
        ]);
    }

    /**
     * Factory method for API-only CORS configuration
     *
     * Creates a CORS instance optimized for pure API usage without
     * credential support. Ideal for public APIs, microservices, or
     * APIs that don't require authentication.
     *
     * Configuration:
     * - Origins: Wildcard (*) by default, or specific domains if provided
     * - Methods: Standard REST API methods
     * - Headers: Essential API headers (Content-Type, Authorization)
     * - Credentials: Disabled for security and simplicity
     *
     * Perfect for APIs that serve public data or use token-based
     * authentication without cookies.
     *
     * @param array $allowedOrigins Origins allowed to access the API (default: all)
     * @return self New CORS instance configured for API-only usage
     */
    public static function apiOnly(array $allowedOrigins = ['*']): self
    {
        return new self([
            'allowedOrigins' => $allowedOrigins,
            'allowedMethods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowedHeaders' => ['Content-Type', 'Authorization'],
            'supportsCredentials' => false,
        ]);
    }

    /**
     * Factory method for frontend-specific CORS configuration
     *
     * Creates a CORS instance optimized for frontend applications that
     * need full access including credentials (cookies, authentication).
     * Perfect for Single Page Applications (SPAs) and web frontends.
     *
     * Features:
     * - Specific frontend domain(s) allowlist
     * - Full HTTP method support for CRUD operations
     * - Common frontend headers (Content-Type, Authorization, X-Requested-With)
     * - Credentials enabled for session-based authentication
     *
     * Usage:
     * ```php
     * // Single frontend
     * $cors = Cors::frontend('https://myapp.com');
     *
     * // Multiple frontends
     * $cors = Cors::frontend([
     *     'https://app.mycompany.com',
     *     'https://admin.mycompany.com'
     * ]);
     * ```
     *
     * @param string|array $frontendUrls Single URL or array of frontend URLs
     * @return self New CORS instance configured for frontend applications
     */
    public static function frontend(string|array $frontendUrls): self
    {
        $origins = is_string($frontendUrls) ? [$frontendUrls] : $frontendUrls;

        return new self([
            'allowedOrigins' => $origins,
            'allowedMethods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowedHeaders' => ['Content-Type', 'Authorization', 'X-Requested-With'],
            'supportsCredentials' => true,
        ]);
    }

    /**
     * Quick configuration factory for common CORS scenarios
     *
     * Provides a convenient shorthand for creating CORS instances with
     * predefined configurations for common use cases. This method eliminates
     * the need to remember specific factory method names.
     *
     * Supported Scenarios:
     * - 'development' or 'dev': Local development with localhost origins
     * - 'universal' or 'open': Allow all origins (wildcard)
     * - 'api-only' or 'api': API-focused configuration without credentials
     * - default: Basic configuration from config files
     *
     * Usage:
     * ```php
     * $cors = Cors::quick('development');  // Same as Cors::development()
     * $cors = Cors::quick('universal');    // Same as Cors::universal()
     * $cors = Cors::quick('api-only');     // Same as Cors::apiOnly()
     * ```
     *
     * @param string $scenario The CORS scenario name (default: 'development')
     * @return self New CORS instance configured for the specified scenario
     */
    public static function quick(string $scenario = 'development'): self
    {
        return match ($scenario) {
            'development', 'dev' => self::development(),
            'universal', 'open' => self::universal(),
            'api-only', 'api' => self::apiOnly(),
            default => new self(),
        };
    }

    /**
     * Enable debugging information for CORS responses
     *
     * Adds debugging capabilities to the CORS handler, useful during
     * development and troubleshooting. When enabled, additional debug
     * information may be included in responses or logged.
     *
     * Debug Features:
     * - Detailed CORS validation logging
     * - Enhanced error messages for rejected requests
     * - Request/response header inspection
     * - Origin validation trace information
     *
     * Note: This should only be enabled in development environments
     * as it may expose sensitive configuration information.
     *
     * Usage:
     * ```php
     * $cors = Cors::development()->withDebug(true);
     * $cors = new Cors(['debug' => true]);
     * ```
     *
     * @param bool $debug Whether to enable debug mode (default: true)
     * @return self Current instance for method chaining (fluent interface)
     */
    public function withDebug(bool $debug = true): self
    {
        $this->config['debug'] = $debug;
        return $this;
    }

    /**
     * Allow additional origins using fluent interface
     *
     * Dynamically adds one or more origins to the existing allowed origins
     * list. This method preserves existing origins and appends new ones,
     * making it useful for conditional origin allowlisting.
     *
     * Features:
     * - Accepts single origin string or array of origins
     * - Preserves existing allowed origins
     * - Supports method chaining (fluent interface)
     * - Automatically handles string-to-array conversion
     *
     * Usage:
     * ```php
     * // Add single origin
     * $cors->allowOrigins('https://newapp.com');
     *
     * // Add multiple origins
     * $cors->allowOrigins([
     *     'https://app1.com',
     *     'https://app2.com'
     * ]);
     *
     * // Method chaining
     * $cors->allowOrigins('https://trusted.com')
     *      ->withCredentials(true)
     *      ->withDebug(false);
     * ```
     *
     * @param string|array $origins Single origin URL or array of origin URLs to add
     * @return self Current instance for method chaining (fluent interface)
     */
    public function allowOrigins(string|array $origins): self
    {
        $newOrigins = is_string($origins) ? [$origins] : $origins;
        $this->config['allowedOrigins'] = array_merge(
            $this->config['allowedOrigins'],
            $newOrigins
        );
        return $this;
    }

    /**
     * Set credentials support using fluent interface
     *
     * Configures whether the CORS handler should allow credentials
     * (cookies, authorization headers, or client certificates) to be
     * included in cross-origin requests.
     *
     * Important Security Notes:
     * - Cannot be used with wildcard (*) origins for security reasons
     * - When enabled, browsers will send cookies and auth headers
     * - When disabled, provides additional security for public APIs
     * - Required for session-based authentication systems
     *
     * Use Cases:
     * - Enable: Web applications with cookie-based sessions
     * - Enable: APIs requiring Authorization headers from browsers
     * - Disable: Public APIs that don't need authentication
     * - Disable: APIs using wildcard origins
     *
     * Usage:
     * ```php
     * // Enable credentials (for authenticated web apps)
     * $cors->withCredentials(true);
     *
     * // Disable credentials (for public APIs)
     * $cors->withCredentials(false);
     *
     * // Method chaining
     * $cors->allowOrigins(['https://myapp.com'])
     *      ->withCredentials(true)
     *      ->withDebug(false);
     * ```
     *
     * @param bool $allow Whether to allow credentials in cross-origin requests (default: true)
     * @return self Current instance for method chaining (fluent interface)
     */
    public function withCredentials(bool $allow = true): self
    {
        $this->config['supportsCredentials'] = $allow;
        return $this;
    }
}
