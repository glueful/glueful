<?php

namespace Glueful\Security;

use Glueful\Helpers\{ConfigManager, Utils};
use Glueful\Exceptions\RateLimitExceededException;
use Glueful\Exceptions\SecurityException;
use Glueful\Cache\CacheEngine;
use Symfony\Component\HttpFoundation\Request;

/**
 * Security Manager Class
 *
 * Provides centralized security validation and rate limiting functionality for the Glueful API.
 * This class handles request validation, content type checking, rate limiting, and other
 * security-related operations to protect the API from malicious requests and abuse.
 *
 * Features:
 * - Request validation (content type, size, headers)
 * - Rate limiting with IP-based tracking
 * - User-Agent validation and suspicious pattern detection
 * - Configurable security policies via config files
 * - Integration with caching systems for rate limit storage
 *
 * @package Glueful\Security
 * @author Glueful Core Team
 * @since 1.0.0
 */
class SecurityManager
{
    /**
     * Security configuration array loaded from config files
     *
     * Contains settings for:
     * - rate_limit: Rate limiting configuration
     * - request_validation: Request validation rules
     * - cors: Cross-origin resource sharing settings
     *
     * @var array
     */
    private array $config;

    /**
     * Initialize the Security Manager
     *
     * Loads security configuration from the ConfigManager and sets up
     * default values for rate limiting and request validation.
     */
    public function __construct()
    {
        $this->config = ConfigManager::get('security', []);

        // Initialize cache engine for rate limiting if not already initialized
        if (!CacheEngine::isEnabled()) {
            $cachePrefix = config('cache.prefix', 'glueful:');
            $cacheDriver = config('cache.default', 'redis');
            CacheEngine::initialize($cachePrefix, $cacheDriver);
        }
    }

    /**
     * Enforce rate limiting for incoming requests
     *
     * Implements IP-based rate limiting to prevent API abuse. Checks against
     * configured limits and throws an exception if the limit is exceeded.
     * Supports IP whitelisting for trusted sources.
     *
     * Rate limiting configuration:
     * - enabled: Whether rate limiting is active
     * - whitelist_ips: Array of IPs to exclude from rate limiting
     * - default_limit: Maximum requests allowed per window
     * - window_seconds: Time window for rate limit calculation
     *
     * @param string $ip The IP address to check rate limits for
     * @throws RateLimitExceededException When the rate limit is exceeded
     * @return void
     */
    public function enforceRateLimit(string $ip): void
    {
        // Skip rate limiting if disabled in configuration
        if (!($this->config['rate_limit']['enabled'] ?? true)) {
            return;
        }

        // Skip if IP is whitelisted - allows trusted IPs to bypass limits
        $whitelist = $this->config['rate_limit']['whitelist_ips'] ?? [];
        if (in_array($ip, $whitelist)) {
            return;
        }

        // Get rate limiting configuration with sensible defaults
        $limit = $this->config['rate_limit']['default_limit'] ?? 1000;      // 1000 requests
        $window = $this->config['rate_limit']['window_seconds'] ?? 3600;     // per hour

        // Generate cache key for this IP's rate limit counter
        $key = "rate_limit:$ip";
        $current = $this->getCacheValue($key, 0);

        // Check if current request count exceeds the limit
        if ($current >= $limit) {
            throw new RateLimitExceededException("Rate limit exceeded for IP: $ip", $window);
        }

        // Increment the counter for this IP with TTL equal to the window
        $this->incrementCacheValue($key, $window);
    }

    /**
     * Validate incoming HTTP requests for security compliance
     *
     * Performs comprehensive validation of HTTP requests including:
     * - Content type validation for POST/PUT/PATCH requests
     * - Request size limits to prevent DoS attacks
     * - User-Agent header validation
     * - Suspicious user agent pattern detection
     *
     * This method is designed to be called centrally before route processing
     * to ensure all requests meet security standards.
     *
     * Validation Rules:
     * - Content-Type must be in allowed list for data-modifying requests
     * - Request size must not exceed configured maximum
     * - User-Agent may be required based on configuration
     * - Suspicious patterns in User-Agent can be blocked
     *
     * @param Request|null $request The HTTP request object to validate.
     *                             If null, creates from globals as fallback
     * @throws SecurityException When validation fails (400, 403, 413, 415 status codes)
     * @return void
     */
    public function validateRequest($request = null): void
    {
        // Check for empty $request and use fallback if needed
        // This ensures the method always has a valid request object to work with
        if (empty($request)) {
            $request = Request::createFromGlobals();
        }

        $method = $request->getMethod();

        // Validate content type for POST/PUT/PATCH requests
        // These methods typically send data and need proper content type headers
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $contentType = $request->headers->get('Content-Type');

            // Get allowed content types from configuration
            $allowedTypes = $this->config['request_validation']['allowed_content_types'] ?? [
                'application/json',                    // JSON API requests
                'application/x-www-form-urlencoded',  // Form submissions
                'multipart/form-data'                 // File uploads
            ];

            // Validate content type if present and allowed types are configured
            if ($contentType && !empty($allowedTypes)) {
                $isAllowed = false;

                // Check if content type starts with any allowed type
                // Using strpos for partial matching (handles charset, boundary params)
                foreach ($allowedTypes as $type) {
                    if (strpos($contentType, $type) === 0) {
                        $isAllowed = true;
                        break;
                    }
                }

                // Reject unsupported content types
                if (!$isAllowed) {
                    throw new SecurityException("Unsupported content type: $contentType", 415);
                }
            }
        }

        // Validate request size to prevent large payload DoS attacks
        $maxSizeConfig = $this->config['request_validation']['max_request_size'] ?? '10MB';
        $maxSize = Utils::parseSize($maxSizeConfig);  // Convert human-readable size to bytes
        $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($contentLength > $maxSize) {
            throw new SecurityException("Request too large", 413);
        }

        // Validate User-Agent if required by configuration
        // Some APIs require User-Agent to identify the client application
        if ($this->config['request_validation']['require_user_agent'] ?? false) {
            $userAgent = $request->headers->get('User-Agent');
            if (empty($userAgent)) {
                throw new SecurityException("User-Agent header required", 400);
            }
        }

        // Check for suspicious user agents if configured
        // Helps block automated scrapers, bots, and malicious tools
        if ($this->config['request_validation']['block_suspicious_ua'] ?? false) {
            $userAgent = $request->headers->get('User-Agent');

            // Default patterns to detect common automated tools
            $suspiciousPatterns = $this->config['request_validation']['suspicious_ua_patterns'] ?? [
                '/bot/i',      // Generic bots
                '/crawler/i',  // Web crawlers
                '/spider/i',   // Web spiders
                '/scraper/i'   // Data scrapers
            ];

            // Check user agent against each suspicious pattern
            foreach ($suspiciousPatterns as $pattern) {
                if (preg_match($pattern, $userAgent)) {
                    throw new SecurityException("Suspicious user agent blocked", 403);
                }
            }
        }
    }

    /**
     * Retrieve a value from the cache system
     *
     * This method integrates with the application's caching system to store
     * and retrieve rate limiting counters and other security-related data.
     *
     * @param string $key The cache key to retrieve
     * @param mixed $default The default value to return if key doesn't exist
     * @return mixed The cached value or default if not found
     */
    private function getCacheValue(string $key, $default = null)
    {
        if (!CacheEngine::isEnabled()) {
            return $default;
        }

        $value = CacheEngine::get($key);
        return $value !== null ? $value : $default;
    }

    /**
     * Increment a value in the cache system with TTL
     *
     * This method increments a counter in the cache (for rate limiting)
     * and sets an expiration time. If the key doesn't exist, it should
     * be created with an initial value of 1.
     *
     * @param string $key The cache key to increment
     * @param int $ttl Time-to-live in seconds for the cache entry
     * @return void
     */
    private function incrementCacheValue(string $key, int $ttl): void
    {
        if (!CacheEngine::isEnabled()) {
            return;
        }

        // Get current value or start at 0
        $current = (int) $this->getCacheValue($key, 0);

        // Increment and set with TTL
        CacheEngine::set($key, $current + 1, $ttl);
    }
}
