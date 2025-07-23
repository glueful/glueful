# Middleware Integration Guide

This guide covers middleware integration patterns and best practices for the Glueful framework, focusing on practical implementation and security considerations.

## Overview

Glueful implements PSR-15 middleware architecture providing standardized request/response handling with dependency injection integration. This guide focuses on integration patterns rather than basic middleware usage.

## Key Integration Patterns

### 1. Security Middleware Stack

Recommended security middleware ordering:

```php
// Recommended security stack
Router::addMiddlewares([
    new SecurityHeadersMiddleware(),
    new CorsMiddleware(),
    new RateLimiterMiddleware(),
    new AuthenticationMiddleware(),
    new AuthorizationMiddleware(),
]);
```

### 2. API Middleware Integration

```php
// API-specific middleware stack
Router::group('/api', function() {
    // API routes
}, middleware: [
    new ApiVersionMiddleware(),
    new RateLimiterMiddleware(100, 3600), // 100 requests per hour
    new AuthenticationMiddleware(),
    new LoggerMiddleware('api-requests'),
]);
```

### 3. Performance Middleware

```php
// Performance optimization stack
Router::addMiddlewares([
    new CacheControlMiddleware(),
    new CompressionMiddleware(),
    new ResponseTimeMiddleware(),
]);
```

## Integration with Glueful Services

### Database Transaction Middleware

```php
class DatabaseTransactionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private DatabaseInterface $database
    ) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        return $this->database->transaction(function() use ($request, $handler) {
            return $handler->handle($request);
        });
    }
}
```

### Notification Middleware

```php
class NotificationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        $response = $handler->handle($request);
        
        // Send notifications for specific actions
        if ($response->getStatusCode() === 201) {
            $this->notificationService->sendCreationNotification($request);
        }
        
        return $response;
    }
}
```

## Best Practices

1. **Order Matters**: Place security middleware first, performance middleware last
2. **Environment-Specific**: Use different middleware stacks for dev/prod
3. **Error Handling**: Implement proper exception handling in middleware
4. **Performance**: Consider middleware overhead, especially for high-traffic routes
5. **Testing**: Create test-specific middleware for mocking external services

## Common Integration Patterns

For detailed middleware implementation examples, see:
- [Security documentation](SECURITY.md) - Security-related middleware
- [Performance documentation](PERFORMANCE_OPTIMIZATION.md) - Performance middleware
- [Console Commands](CONSOLE_COMMANDS.md) - CLI middleware management