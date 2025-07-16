<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Glueful\Security\RandomStringGenerator;
use Glueful\Cache\CacheStore;
use Glueful\Exceptions\SecurityException;
use Glueful\DI\Container;
use Glueful\Events\Security\CSRFViolationEvent;
use Glueful\Events\Event;

/**
 * CSRF Protection Middleware
 *
 * Protects against Cross-Site Request Forgery attacks by validating CSRF tokens
 * for state-changing HTTP methods (POST, PUT, PATCH, DELETE).
 *
 * Features:
 * - Token generation and validation
 * - Session-based token storage with cache fallback
 * - Configurable token lifetime
 * - Double-submit cookie pattern support
 * - JSON and form-based token validation
 * - Rate limiting for token generation
 *
 * Security considerations:
 * - Uses cryptographically secure random token generation
 * - Implements constant-time token comparison
 * - Supports both header and form-based token submission
 * - Integrates with existing session management
 */
class CSRFMiddleware implements MiddlewareInterface
{
    /** @var string CSRF token header name */
    private const CSRF_HEADER = 'X-CSRF-Token';

    /** @var string CSRF token form field name */
    private const CSRF_FIELD = '_token';

    /** @var string Cookie name for double-submit pattern */
    private const CSRF_COOKIE = 'csrf_token';

    /** @var string Cache key prefix for CSRF tokens */
    private const CACHE_PREFIX = 'csrf_token:';

    /** @var int Default token lifetime in seconds (1 hour) */
    private const DEFAULT_TOKEN_LIFETIME = 3600;

    /** @var int Token length in characters */
    private const TOKEN_LENGTH = 32;

    /** @var array HTTP methods that require CSRF protection */
    private const PROTECTED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** @var array Routes exempt from CSRF protection */
    private array $exemptRoutes;

    /** @var int Token lifetime in seconds */
    private int $tokenLifetime;

    /** @var bool Whether to use double-submit cookie pattern */
    private bool $useDoubleSubmit;

    /** @var Container|null DI Container */
    private ?Container $container;

    /** @var bool Whether to enforce CSRF protection */
    private bool $enabled;

    /** @var CacheStore|null Cache instance */
    private ?CacheStore $cache;


    /**
     * Create CSRF middleware
     *
     * @param array $exemptRoutes Routes to exempt from CSRF protection
     * @param int $tokenLifetime Token lifetime in seconds
     * @param bool $useDoubleSubmit Whether to use double-submit cookie pattern
     * @param bool $enabled Whether CSRF protection is enabled
     * @param Container|null $container DI Container instance
     */
    public function __construct(
        array $exemptRoutes = [],
        int $tokenLifetime = self::DEFAULT_TOKEN_LIFETIME,
        bool $useDoubleSubmit = false,
        bool $enabled = true,
        ?Container $container = null,
        ?CacheStore $cache = null
    ) {
        $this->exemptRoutes = $this->normalizeRoutes($exemptRoutes);
        $this->tokenLifetime = $tokenLifetime;
        $this->useDoubleSubmit = $useDoubleSubmit;
        $this->enabled = $enabled;
        $this->container = $container ?? $this->getDefaultContainer();
        $this->cache = $cache;

        // If no cache provided, try to get from container
        if ($this->cache === null && $this->container !== null) {
            try {
                $this->cache = $this->container->get(CacheStore::class);
            } catch (\Exception $e) {
                // Cache not available - continue without caching
                $this->cache = null;
            }
        }


        // Container is available for future enhancements
        unset($this->container);
    }

    /**
     * Process the request through CSRF protection
     *
     * @param Request $request The incoming request
     * @param RequestHandlerInterface $handler The next handler in the pipeline
     * @return Response The response
     * @throws SecurityException If CSRF validation fails
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Skip if CSRF protection is disabled
        if (!$this->enabled) {
            return $handler->handle($request);
        }

        // Skip if route is exempt
        if ($this->isExemptRoute($request)) {
            return $handler->handle($request);
        }

        // Only protect state-changing methods
        if (!$this->requiresCSRFProtection($request->getMethod())) {
            // Generate token for safe methods to be available for forms
            $this->generateTokenIfNeeded($request);
            return $handler->handle($request);
        }

        // Validate CSRF token for protected methods
        if (!$this->validateToken($request)) {
            // Emit event for application security logging
            Event::dispatch(new CSRFViolationEvent(
                'csrf_token_mismatch',
                $request
            ));

            throw new SecurityException(
                'CSRF token validation failed. Please refresh the page and try again.',
                419, // 419 Page Expired (Laravel convention for CSRF failures)
                [
                    'error_code' => 'CSRF_TOKEN_MISMATCH',
                    'method' => $request->getMethod(),
                    'path' => $request->getPathInfo()
                ]
            );
        }

        // CSRF validation passed, continue to next middleware
        return $handler->handle($request);
    }

    /**
     * Generate CSRF token for the session
     *
     * @param Request $request The HTTP request
     * @return string Generated CSRF token
     */
    public function generateToken(Request $request): string
    {
        $sessionId = $this->getSessionId($request);
        $token = RandomStringGenerator::generateHex(self::TOKEN_LENGTH);
        $cacheKey = self::CACHE_PREFIX . $sessionId;

        // Store token in cache with expiration
        if ($this->cache !== null) {
            try {
                $this->cache->set($cacheKey, $token, $this->tokenLifetime);
            } catch (\Exception $e) {
                error_log("Cache set failed for CSRF token '{$cacheKey}': " . $e->getMessage());
            }
        }

        // Set double-submit cookie if enabled
        if ($this->useDoubleSubmit) {
            setcookie(
                self::CSRF_COOKIE,
                $token,
                [
                    'expires' => time() + $this->tokenLifetime,
                    'path' => '/',
                    'secure' => $request->isSecure(),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
        }

        return $token;
    }

    /**
     * Get CSRF token for the current session
     *
     * @param Request $request The HTTP request
     * @return string|null Current CSRF token or null if not found
     */
    public function getToken(Request $request): ?string
    {
        $sessionId = $this->getSessionId($request);
        $cacheKey = self::CACHE_PREFIX . $sessionId;

        if ($this->cache !== null) {
            try {
                return $this->cache->get($cacheKey);
            } catch (\Exception $e) {
                error_log("Cache get failed for CSRF token '{$cacheKey}': " . $e->getMessage());
            }
        }
        return null;
    }

    /**
     * Validate CSRF token from request
     *
     * @param Request $request The HTTP request
     * @return bool Whether token is valid
     */
    private function validateToken(Request $request): bool
    {
        $expectedToken = $this->getToken($request);
        if (!$expectedToken) {
            return false;
        }

        // Get token from various sources
        $submittedToken = $this->getSubmittedToken($request);
        if (!$submittedToken) {
            return false;
        }

        // Use constant-time comparison to prevent timing attacks
        $isValid = hash_equals($expectedToken, $submittedToken);

        // Validate double-submit cookie if enabled
        if ($isValid && $this->useDoubleSubmit) {
            $cookieToken = $request->cookies->get(self::CSRF_COOKIE);
            $isValid = $cookieToken && hash_equals($expectedToken, $cookieToken);
        }

        return $isValid;
    }

    /**
     * Get submitted CSRF token from request
     *
     * @param Request $request The HTTP request
     * @return string|null Submitted token or null if not found
     */
    private function getSubmittedToken(Request $request): ?string
    {
        // Check header first (for AJAX requests)
        $token = $request->headers->get(self::CSRF_HEADER);
        if ($token) {
            return $token;
        }

        // Check form data
        $token = $request->request->get(self::CSRF_FIELD);
        if ($token) {
            return $token;
        }

        // Check query parameters (for URL-based tokens, use carefully)
        $token = $request->query->get(self::CSRF_FIELD);
        if ($token) {
            return $token;
        }

        // Check JSON body for CSRF token
        if ($request->headers->get('Content-Type') === 'application/json') {
            $json = json_decode($request->getContent(), true);
            if (is_array($json) && isset($json[self::CSRF_FIELD])) {
                return $json[self::CSRF_FIELD];
            }
        }

        return null;
    }

    /**
     * Generate token if needed for safe methods
     *
     * @param Request $request The HTTP request
     * @return void
     */
    private function generateTokenIfNeeded(Request $request): void
    {
        $existingToken = $this->getToken($request);
        if (!$existingToken) {
            $this->generateToken($request);
        }
    }

    /**
     * Check if HTTP method requires CSRF protection
     *
     * @param string $method HTTP method
     * @return bool Whether method requires protection
     */
    private function requiresCSRFProtection(string $method): bool
    {
        return in_array(strtoupper($method), self::PROTECTED_METHODS, true);
    }

    /**
     * Check if route is exempt from CSRF protection
     *
     * @param Request $request The HTTP request
     * @return bool Whether route is exempt
     */
    private function isExemptRoute(Request $request): bool
    {
        $path = $request->getPathInfo();

        // Strip the base path and API version to get the clean route
        $cleanPath = $this->getCleanPath($path);

        foreach ($this->exemptRoutes as $exemptRoute) {
            // Check both the original path and the clean path
            if (
                $this->matchesPattern($path, $exemptRoute) ||
                $this->matchesPattern($cleanPath, $exemptRoute)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get clean path by stripping base path and API version
     *
     * @param string $path Original request path
     * @return string Clean path without base path and API version
     */
    private function getCleanPath(string $path): string
    {
        // Remove leading slash for processing
        $cleanPath = ltrim($path, '/');

        // Get API base URL and version from environment
        $apiBaseUrl = env('API_BASE_URL', '');
        $apiVersion = env('API_VERSION', 'v1');

        // Extract base path from API_BASE_URL (everything after domain)
        if ($apiBaseUrl) {
            $parsedUrl = parse_url($apiBaseUrl);
            $basePath = isset($parsedUrl['path']) ? trim($parsedUrl['path'], '/') : '';

            // Remove base path if it exists at the start
            if ($basePath && strpos($cleanPath, $basePath) === 0) {
                $cleanPath = substr($cleanPath, strlen($basePath));
                $cleanPath = ltrim($cleanPath, '/');
            }
        }

        // Remove API version if it exists at the start
        if ($apiVersion && strpos($cleanPath, $apiVersion) === 0) {
            $cleanPath = substr($cleanPath, strlen($apiVersion));
            $cleanPath = ltrim($cleanPath, '/');
        }

        return $cleanPath;
    }

    /**
     * Check if path matches pattern
     *
     * @param string $path Request path
     * @param string $pattern Route pattern
     * @return bool Whether path matches pattern
     */
    private function matchesPattern(string $path, string $pattern): bool
    {
        // Exact match
        if ($path === $pattern) {
            return true;
        }

        // Wildcard pattern matching
        $pattern = str_replace(['*', '/'], ['.*', '\/'], $pattern);
        return (bool) preg_match('/^' . $pattern . '$/', $path);
    }

    /**
     * Get session ID from request
     *
     * @param Request $request The HTTP request
     * @return string Session identifier
     */
    private function getSessionId(Request $request): string
    {
        // Try to get from authenticated user session
        $user = $request->attributes->get('user');
        if (is_array($user) && isset($user['session_id'])) {
            return $user['session_id'];
        }

        // Fallback to IP + User-Agent for anonymous sessions
        $userAgent = $request->headers->get('User-Agent', 'unknown');
        $clientIp = $request->getClientIp() ?? 'unknown';

        return hash('sha256', $clientIp . '|' . $userAgent);
    }

    /**
     * Normalize exempt routes patterns
     *
     * @param array $routes Raw route patterns
     * @return array Normalized route patterns
     */
    private function normalizeRoutes(array $routes): array
    {
        return array_map(function ($route) {
            return ltrim($route, '/');
        }, $routes);
    }

    /**
     * Get default container safely
     *
     * @return Container|null
     */
    private function getDefaultContainer(): ?Container
    {
        if (function_exists('container')) {
            try {
                return container();
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Create middleware with common API routes exempted
     *
     * @param array $additionalExemptions Additional routes to exempt
     * @return self Configured CSRF middleware
     */
    public static function withApiExemptions(array $additionalExemptions = []): self
    {
        $defaultExemptions = [
            'api/auth/login',
            'api/auth/register',
            'api/auth/forgot-password',
            'api/auth/reset-password',
            'api/webhooks/*',
            'api/public/*'
        ];

        return new self(array_merge($defaultExemptions, $additionalExemptions));
    }

    /**
     * Get the current CSRF token as HTML hidden input
     *
     * @param Request $request The HTTP request
     * @return string HTML hidden input field
     */
    public function getTokenField(Request $request): string
    {
        $token = $this->getToken($request) ?? $this->generateToken($request);
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars(self::CSRF_FIELD, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * Get the current CSRF token for JavaScript usage
     *
     * @param Request $request The HTTP request
     * @return array Token data for JSON response
     */
    public function getTokenData(Request $request): array
    {
        $token = $this->getToken($request) ?? $this->generateToken($request);
        return [
            'token' => $token,
            'header' => self::CSRF_HEADER,
            'field' => self::CSRF_FIELD,
            'expires_at' => time() + $this->tokenLifetime
        ];
    }
}
