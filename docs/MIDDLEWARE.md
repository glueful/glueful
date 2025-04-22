# Glueful PSR-15 Middleware System

## Overview

The Glueful framework implements a standardized middleware architecture based on PSR-15 principles, providing a robust and flexible way to handle cross-cutting concerns in your application. This documentation explains how to use, create, and extend the middleware system.

## Table of Contents

- [Glueful PSR-15 Middleware System](#glueful-psr-15-middleware-system)
  - [Overview](#overview)
  - [Table of Contents](#table-of-contents)
  - [Introduction to Middleware](#introduction-to-middleware)
  - [Core Middleware Interfaces](#core-middleware-interfaces)
    - [MiddlewareInterface](#middlewareinterface)
    - [RequestHandlerInterface](#requesthandlerinterface)
  - [Built-in Middleware Components](#built-in-middleware-components)
    - [AuthenticationMiddleware](#authenticationmiddleware)
    - [CorsMiddleware](#corsmiddleware)
    - [RateLimiterMiddleware](#ratelimitermiddleware)
    - [LoggerMiddleware](#loggermiddleware)
    - [CacheControlMiddleware](#cachecontrolmiddleware)
    - [SecurityHeadersMiddleware](#securityheadersmiddleware)
  - [Using Middleware in Your Application](#using-middleware-in-your-application)
    - [Global Middleware](#global-middleware)
    - [Route-Specific Middleware](#route-specific-middleware)
    - [Route Groups with Custom Middleware](#route-groups-with-custom-middleware)
  - [Creating Custom Middleware](#creating-custom-middleware)
  - [Advanced Middleware Techniques](#advanced-middleware-techniques)
    - [Middleware with Dependencies](#middleware-with-dependencies)
    - [Conditional Middleware](#conditional-middleware)
  - [Middleware Ordering Best Practices](#middleware-ordering-best-practices)
  - [Migrating from Legacy Middleware](#migrating-from-legacy-middleware)
    - [Manual Conversion](#manual-conversion)
    - [Automatic Conversion](#automatic-conversion)
  - [Conclusion](#conclusion)

## Introduction to Middleware

Middleware components act as layers in your application's request handling pipeline. Each middleware can perform actions before and after the request is processed by the rest of the application. This enables:

- Cross-cutting concerns like logging, authentication, and rate limiting
- Request/response manipulation
- Early request termination for rejected requests
- Clean separation of concerns in your application

The Glueful middleware system follows PSR-15 principles, making it compatible with the broader PHP ecosystem while being optimized for the Glueful framework's specific architecture.

## Core Middleware Interfaces

The middleware system is built on two key interfaces:

### MiddlewareInterface

```php
namespace Glueful\Http\Middleware;

interface MiddlewareInterface
{
    public function process(Request $request, RequestHandlerInterface $handler): Response;
}
```

The `process()` method:
- Receives the request and a reference to the next handler in the pipeline
- Can modify the request before passing it to the next handler
- Can modify the response after receiving it from the next handler
- Can choose not to call the next handler and return a response directly

### RequestHandlerInterface

```php
namespace Glueful\Http\Middleware;

interface RequestHandlerInterface
{
    public function handle(Request $request): Response;
}
```

The `handle()` method:
- Receives a request and returns a response
- Used by middleware to invoke the next handler in the pipeline

## Built-in Middleware Components

Glueful provides several ready-to-use middleware components:

### AuthenticationMiddleware

Handles user authentication and authorization.

```php
// Regular user authentication
Router::addMiddleware(new AuthenticationMiddleware());

// Admin authentication
Router::addMiddleware(new AuthenticationMiddleware(true));
```

### CorsMiddleware

Manages Cross-Origin Resource Sharing (CORS) headers.

```php
// With default configuration
Router::addMiddleware(new CorsMiddleware());

// With custom configuration
Router::addMiddleware(new CorsMiddleware([
    'allowedOrigins' => ['https://example.com'],
    'allowedMethods' => ['GET', 'POST', 'PUT'],
    'allowedHeaders' => ['Content-Type', 'Authorization'],
    'exposedHeaders' => ['X-Custom-Header'],
    'maxAge' => 3600,
    'supportsCredentials' => true,
]));
```

### RateLimiterMiddleware

Implements request rate limiting to protect your API from abuse.

```php
// Default: 60 requests per minute, IP-based
Router::addMiddleware(new RateLimiterMiddleware());

// Custom limits and user-based
Router::addMiddleware(new RateLimiterMiddleware(
    maxAttempts: 100,
    windowSeconds: 3600, // 1 hour
    type: 'user'
));
```

### LoggerMiddleware

Logs API requests and responses for monitoring and debugging.

```php
// Default configuration
Router::addMiddleware(new LoggerMiddleware());

// Custom channel and level
Router::addMiddleware(new LoggerMiddleware('api-requests', 'debug'));
```

### CacheControlMiddleware

Manages cache headers for optimizing client-side caching.

```php
// Default configuration
Router::addMiddleware(new CacheControlMiddleware());

// Custom configuration
Router::addMiddleware(new CacheControlMiddleware([
    'public' => true,
    'max_age' => 3600,
    'methods' => [
        'GET' => true,
        'HEAD' => true,
        'POST' => false,
    ],
    'routes' => [
        'GET /api/users' => ['max_age' => 300],
    ],
]));
```

### SecurityHeadersMiddleware

Adds security-related HTTP headers to protect against common vulnerabilities.

```php
// Default configuration
Router::addMiddleware(new SecurityHeadersMiddleware());

// Custom configuration
Router::addMiddleware(new SecurityHeadersMiddleware([
    'content_security_policy' => [
        'enabled' => true,
        'directives' => [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "https://trusted-cdn.com"],
        ],
    ],
    'x_frame_options' => 'SAMEORIGIN',
]));
```

## Using Middleware in Your Application

### Global Middleware

Register middleware that should run for all requests:

```php
// In your bootstrap file
use Glueful\Http\Router;
use Glueful\Http\Middleware\CorsMiddleware;
use Glueful\Http\Middleware\LoggerMiddleware;
use Glueful\Http\Middleware\SecurityHeadersMiddleware;

// Individual registration
Router::addMiddleware(new CorsMiddleware());
Router::addMiddleware(new LoggerMiddleware());

// Or register multiple middleware at once
Router::addMiddlewares([
    new SecurityHeadersMiddleware(),
    new RateLimiterMiddleware(60, 60),
]);
```

### Route-Specific Middleware

For certain routes or route groups:

```php
// Protect admin routes with authentication
Router::group('/admin', function() {
    Router::get('/stats', [AdminController::class, 'stats']);
    Router::get('/users', [AdminController::class, 'users']);
}, requiresAuth: true, requiresAdminAuth: true);
```

### Route Groups with Custom Middleware

Create route groups with specific middleware:

```php
// Apply rate limiting to auth endpoints
$authRateLimiter = new RateLimiterMiddleware(5, 60); // 5 attempts per minute

Router::group('/auth', function() {
    Router::post('/login', [AuthController::class, 'login']);
    Router::post('/register', [AuthController::class, 'register']);
});

// Add the middleware to the router
Router::addMiddleware($authRateLimiter);
```

## Creating Custom Middleware

To create your own middleware, implement the `MiddlewareInterface`:

```php
<?php

namespace App\Http\Middleware;

use Glueful\Http\Middleware\MiddlewareInterface;
use Glueful\Http\Middleware\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

class ApiVersionMiddleware implements MiddlewareInterface
{
    private string $supportedVersion;
    
    public function __construct(string $supportedVersion = '1.0')
    {
        $this->supportedVersion = $supportedVersion;
    }
    
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Extract API version from request header
        $requestedVersion = $request->headers->get('X-API-Version');
        
        // Check if version is supported
        if ($requestedVersion && $requestedVersion !== $this->supportedVersion) {
            return new JsonResponse([
                'success' => false,
                'message' => 'API version not supported',
                'code' => 400
            ], 400);
        }
        
        // Add the API version to the response
        $response = $handler->handle($request);
        $response->headers->set('X-API-Version', $this->supportedVersion);
        
        return $response;
    }
}
```

Then register it with the router:

```php
use App\Http\Middleware\ApiVersionMiddleware;

Router::addMiddleware(new ApiVersionMiddleware('2.0'));
```

## Advanced Middleware Techniques

### Middleware with Dependencies

Inject services into your middleware:

```php
class DatabaseTransactionMiddleware implements MiddlewareInterface
{
    private Connection $connection;
    
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }
    
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Start transaction
        $this->connection->beginTransaction();
        
        try {
            // Process request
            $response = $handler->handle($request);
            
            // Commit transaction if successful
            $this->connection->commit();
            
            return $response;
        } catch (\Throwable $e) {
            // Rollback transaction on error
            $this->connection->rollBack();
            throw $e;
        }
    }
}
```

### Conditional Middleware

Run middleware only in certain conditions:

```php
class ConditionalMiddleware implements MiddlewareInterface
{
    private MiddlewareInterface $middleware;
    private callable $condition;
    
    public function __construct(MiddlewareInterface $middleware, callable $condition)
    {
        $this->middleware = $middleware;
        $this->condition = $condition;
    }
    
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if (call_user_func($this->condition, $request)) {
            return $this->middleware->process($request, $handler);
        }
        
        return $handler->handle($request);
    }
}

// Usage
$onlyInProduction = function(Request $request) {
    return config('app.env') === 'production';
};

Router::addMiddleware(new ConditionalMiddleware(
    new SecurityHeadersMiddleware(),
    $onlyInProduction
));
```

## Middleware Ordering Best Practices

The order of middleware registration is important. Here's a recommended order:

1. **Error Handling Middleware** - Catches exceptions and errors
2. **Security Middleware** - CORS, security headers, etc.
3. **Session/State Middleware** - Manages user sessions
4. **Authentication Middleware** - Verifies user identity
5. **Rate Limiting Middleware** - Controls request rates
6. **Logging Middleware** - Records request details
7. **Content Negotiation Middleware** - Handles Accept headers
8. **Body Parsing Middleware** - Processes request body
9. **Route-specific Middleware** - Custom middleware for specific routes

Example configuration:

```php
Router::addMiddlewares([
    new ErrorHandlingMiddleware(),
    new SecurityHeadersMiddleware(),
    new CorsMiddleware(),
    new SessionMiddleware(),
    new AuthenticationMiddleware(),
    new RateLimiterMiddleware(),
    new LoggerMiddleware(),
    new ContentNegotiationMiddleware(),
    new BodyParsingMiddleware(),
]);
```

## Migrating from Legacy Middleware

Glueful provides tools to convert legacy middleware to the new PSR-15 format:

### Manual Conversion

Convert individual middleware:

```php
// Legacy middleware
Router::middleware(function($request) {
    // Legacy middleware logic
    return null; // Continue processing
});

// PSR-15 middleware using converter
$legacyMiddleware = Router::convertToMiddleware(function($request) {
    // Legacy middleware logic
    return null; // Continue processing
});
Router::addMiddleware($legacyMiddleware);
```

### Automatic Conversion

Convert all legacy middleware at once:

```php
// First register all your legacy middleware
Router::middleware(function($request) { /* ... */ });
Router::middleware(function($request) { /* ... */ });

// Then convert them all automatically
Router::convertLegacyMiddleware();
```

## Conclusion

The Glueful PSR-15 middleware system provides a powerful, flexible architecture for handling cross-cutting concerns in your application. By following this documentation, you can leverage the built-in middleware components and create custom ones to meet your specific requirements.

For more information, check out the [PSR-15 specification](https://www.php-fig.org/psr/psr-15/) and explore the Glueful framework's source code for implementation details.