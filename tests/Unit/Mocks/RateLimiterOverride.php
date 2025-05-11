<?php
/**
 * Override file for RateLimiter that adds static method hooks for testing
 */

namespace Glueful\Security;

// Original RateLimiter code would be here
// For testing purposes, we intercept all static method calls

class RateLimiter
{
    /**
     * Create IP-based rate limiter
     * 
     * @param string $ip IP address to track
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return RateLimiter Rate limiter instance
     */
    public static function perIp(string $ip, int $maxAttempts, int $windowSeconds)
    {
        // Check if we have a mock implementation
        if (isset($GLOBALS['MOCK_STATIC_IMPLEMENTATIONS'][self::class . '::perIp'])) {
            return call_user_func_array(
                $GLOBALS['MOCK_STATIC_IMPLEMENTATIONS'][self::class . '::perIp'],
                [$ip, $maxAttempts, $windowSeconds]
            );
        }
        
        // This would normally return a real instance
        // For tests, this fallback should never be reached
        throw new \RuntimeException('No mock implementation for RateLimiter::perIp');
    }
    
    /**
     * Create user-based rate limiter
     * 
     * @param string $userId User identifier to track
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return RateLimiter Rate limiter instance
     */
    public static function perUser(string $userId, int $maxAttempts, int $windowSeconds)
    {
        // Check if we have a mock implementation
        if (isset($GLOBALS['MOCK_STATIC_IMPLEMENTATIONS'][self::class . '::perUser'])) {
            return call_user_func_array(
                $GLOBALS['MOCK_STATIC_IMPLEMENTATIONS'][self::class . '::perUser'],
                [$userId, $maxAttempts, $windowSeconds]
            );
        }
        
        // This would normally return a real instance
        // For tests, this fallback should never be reached
        throw new \RuntimeException('No mock implementation for RateLimiter::perUser');
    }
    
    /**
     * Create endpoint-specific rate limiter
     * 
     * @param string $endpoint API endpoint to track
     * @param string $identifier Unique request identifier
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return RateLimiter Rate limiter instance
     */
    public static function perEndpoint(string $endpoint, string $identifier, int $maxAttempts, int $windowSeconds)
    {
        // Check if we have a mock implementation
        if (isset($GLOBALS['MOCK_STATIC_IMPLEMENTATIONS'][self::class . '::perEndpoint'])) {
            return call_user_func_array(
                $GLOBALS['MOCK_STATIC_IMPLEMENTATIONS'][self::class . '::perEndpoint'],
                [$endpoint, $identifier, $maxAttempts, $windowSeconds]
            );
        }
        
        // This would normally return a real instance
        // For tests, this fallback should never be reached
        throw new \RuntimeException('No mock implementation for RateLimiter::perEndpoint');
    }
    
    /**
     * Record and validate attempt
     * 
     * @return bool True if attempt is allowed
     */
    public function attempt(): bool
    {
        throw new \RuntimeException('Method not implemented in test mock');
    }
    
    /**
     * Get remaining attempts
     * 
     * @return int Remaining attempts
     */
    public function remaining(): int
    {
        throw new \RuntimeException('Method not implemented in test mock');
    }
    
    /**
     * Get retry delay
     * 
     * @return int Seconds until next attempt allowed
     */
    public function getRetryAfter(): int
    {
        throw new \RuntimeException('Method not implemented in test mock');
    }
    
    /**
     * Check if limit exceeded
     * 
     * @return bool True if rate limit is exceeded
     */
    public function isExceeded(): bool
    {
        throw new \RuntimeException('Method not implemented in test mock');
    }
}
