# Rate Limiter Implementation Guide

The `RateLimiter` class provides a sliding window rate limiting implementation using Redis sorted sets (with Memcached fallback). This ensures precise rate limiting by tracking exact timestamps of attempts.

## Basic Usage

```php
use Glueful\Api\Library\Security\RateLimiter;

// Allow 100 requests per minute
$limiter = new RateLimiter(
    key: 'api-endpoint',
    maxAttempts: 100,
    windowSeconds: 60
);

if ($limiter->attempt()) {
    // Process the request
    return handleRequest();
} else {
    $retryAfter = $limiter->getRetryAfter();
    return [
        'error' => 'Rate limit exceeded',
        'retry_after' => $retryAfter,
        'remaining' => $limiter->remaining()
    ];
}
```

## Helper Methods

### IP-based Rate Limiting
```php
// Limit by IP: 60 attempts per hour
$limiter = RateLimiter::perIp(
    ip: $_SERVER['REMOTE_ADDR'],
    maxAttempts: 60,
    windowSeconds: 3600
);
```

### User-based Rate Limiting
```php
// Limit by user: 1000 attempts per day
$limiter = RateLimiter::perUser(
    userId: $user->getId(),
    maxAttempts: 1000,
    windowSeconds: 86400
);
```

### Endpoint-specific Rate Limiting
```php
// Limit specific endpoint: 30 attempts per minute
$limiter = RateLimiter::perEndpoint(
    endpoint: 'search',
    identifier: $userId,
    maxAttempts: 30,
    windowSeconds: 60
);
```

## Available Methods

- `attempt()`: Returns true if attempt is allowed, false if rate limited
- `remaining()`: Get remaining attempts in current window
- `getRetryAfter()`: Get seconds until rate limit resets
- `reset()`: Reset the rate limiter
- `isExceeded()`: Check if rate limit is exceeded

## Middleware Implementation

```php
class RateLimitMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $limiter = RateLimiter::perIp(
            $request->ip(),
            maxAttempts: 60,
            windowSeconds: 60
        );

        if ($limiter->isExceeded()) {
            return response()->json([
                'error' => 'Too Many Requests',
                'retry_after' => $limiter->getRetryAfter(),
                'remaining' => 0
            ], 429)->withHeaders([
                'X-RateLimit-Limit' => 60,
                'X-RateLimit-Remaining' => 0,
                'Retry-After' => $limiter->getRetryAfter()
            ]);
        }

        $response = $next($request);

        return $response->withHeaders([
            'X-RateLimit-Limit' => 60,
            'X-RateLimit-Remaining' => $limiter->remaining(),
            'X-RateLimit-Reset' => time() + $limiter->getRetryAfter()
        ]);
    }
}
```

## Technical Details

- Uses sliding window algorithm for precise rate limiting
- Stores timestamps in Redis sorted sets (or Memcached arrays)
- Automatically cleans up expired attempts
- Thread-safe implementation
- Efficient memory usage
- Supports both Redis and Memcached backends

## Best Practices

1. Use appropriate window sizes for your use case
2. Include rate limit headers in responses
3. Implement graceful fallback handling
4. Consider using multiple limiters for critical endpoints
5. Monitor rate limit metrics
6. Document rate limits in API documentation
