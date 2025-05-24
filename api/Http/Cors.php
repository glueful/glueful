<?php

declare(strict_types=1);

namespace Glueful\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Configurable CORS Handler - Developer-Friendly
 *
 * Features:
 * - Multiple configuration sources (config files, env vars, constructor)
 * - Factory methods for common scenarios
 * - Fluent interface for method chaining
 * - Automatic string-to-array conversion for env vars
 * - Sensible defaults with wildcard fallback
 */
class Cors
{
    private array $config;

    public function __construct(array $config = [])
    {
        // Load origins from config and normalize to array
        $allowedOrigins = config('security.cors.allowed_origins', []);

        // Handle string input (from env vars) by converting to array
        if (is_string($allowedOrigins)) {
            $allowedOrigins = array_map('trim', explode(',', $allowedOrigins));
            $allowedOrigins = array_filter($allowedOrigins);
        }

        // If no origins configured, allow all (wildcard)
        if (empty($allowedOrigins)) {
            $allowedOrigins = ['*'];
        }

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
            'maxAge' => config('security.cors.max_age', 86400),
            'supportsCredentials' => config('security.cors.supports_credentials', true),
        ], $config);
    }

    /**
     * Handle CORS for the incoming request
     */
    public function handle(): bool
    {
        $request = Request::createFromGlobals();
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
     * Handle CORS preflight request
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
     * Add CORS headers for regular requests
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
     * Check if origin is allowed
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
     * Factory method for universal CORS (allows all origins)
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
     * Factory method for development (allows common localhost ports)
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
     * Factory method for production with specific origins
     */
    public static function production(array $allowedOrigins): self
    {
        return new self([
            'allowedOrigins' => $allowedOrigins,
            'supportsCredentials' => true,
        ]);
    }

    /**
     * Factory method for API-only CORS (no credentials)
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
     * Factory method for frontend-specific CORS
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
     * Quick configuration for common scenarios
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
     * Add debugging information to responses (development only)
     */
    public function withDebug(bool $debug = true): self
    {
        $this->config['debug'] = $debug;
        return $this;
    }

    /**
     * Allow additional origins (fluent interface)
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
     * Set credentials support (fluent interface)
     */
    public function withCredentials(bool $allow = true): self
    {
        $this->config['supportsCredentials'] = $allow;
        return $this;
    }
}
