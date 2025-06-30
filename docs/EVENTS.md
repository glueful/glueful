# Glueful Event System Documentation

The Glueful framework includes a comprehensive event system built on Symfony EventDispatcher, providing extensible hooks throughout the application lifecycle for monitoring, logging, and custom functionality.

## Table of Contents

- [Overview](#overview)
- [Event Categories](#event-categories)
- [Authentication Events](#authentication-events)
- [Cache Events](#cache-events)
- [Database Events](#database-events)
- [HTTP Events](#http-events)
- [Security Events](#security-events)
- [Event Listeners](#event-listeners)
- [Extension Integration](#extension-integration)
- [Creating Custom Events](#creating-custom-events)
- [Performance Considerations](#performance-considerations)
- [Best Practices](#best-practices)

## Overview

The event system allows decoupled communication between different parts of the application. Events are dispatched when significant actions occur, and listeners can respond to these events for:

- **Logging and monitoring**
- **Performance analytics**
- **Security monitoring**
- **Cache invalidation**
- **Extension integration**
- **Custom business logic**

### Key Features

- 20+ built-in event classes
- Symfony EventDispatcher integration
- Extension event subscriber system
- Performance monitoring capabilities
- Security-focused event data
- Framework vs Application logging boundaries
- Type-safe PHP 8.2+ implementation

### Framework vs Application Logging

The event system supports clear separation between framework and application concerns:

- **Framework Events**: Infrastructure/protocol concerns (HTTP auth failures, rate limits, protocol errors)
- **Application Events**: Business logic concerns (user actions, business state changes, custom analytics)

Framework emits events that applications can listen to for implementing business-specific logging and responses.

## Event Categories

### 1. Authentication Events (`Glueful\Events\Auth`)
- Session lifecycle events
- Authentication failures
- Rate limiting events

### 2. Cache Events (`Glueful\Events\Cache`)
- Cache hits and misses
- Cache invalidation events

### 3. Database Events (`Glueful\Events\Database`)
- Query execution events
- Entity lifecycle events

### 4. HTTP Events (`Glueful\Events\Http`)
- Request and response events
- Exception handling events
- HTTP authentication events

### 5. Security Events (`Glueful\Events\Security`)
- Rate limiting events
- CSRF violation events
- Framework security events

## Authentication Events

### SessionCreatedEvent

**Triggered**: When a new user session is created during authentication

**Properties**:
```php
readonly array $sessionData  // User data (uuid, username, email)
readonly array $tokens      // Access and refresh tokens
readonly array $metadata    // Additional session metadata
```

**Usage Example**:
```php
use Glueful\Events\Auth\SessionCreatedEvent;

// Listen for session creation
$eventDispatcher->addListener(SessionCreatedEvent::class, function(SessionCreatedEvent $event) {
    $userUuid = $event->getUserUuid();
    $accessToken = $event->getAccessToken();
    
    // Log session creation
    $logger->info('New session created', [
        'user_uuid' => $userUuid,
        'session_data' => $event->getSessionData()
    ]);
});
```

**Key Methods**:
- `getUserUuid(): ?string` - Get user UUID
- `getUsername(): ?string` - Get username
- `getAccessToken(): ?string` - Get access token
- `getRefreshToken(): ?string` - Get refresh token
- `getMetadata(): array` - Get session metadata

### AuthenticationFailedEvent

**Triggered**: When authentication attempts fail

**Properties**:
```php
readonly string $username      // Attempted username/email
readonly string $reason       // Failure reason
readonly ?string $clientIp    // Client IP address
readonly ?string $userAgent   // Client user agent
readonly array $metadata      // Additional failure metadata
```

**Usage Example**:
```php
use Glueful\Events\Auth\AuthenticationFailedEvent;

$eventDispatcher->addListener(AuthenticationFailedEvent::class, function(AuthenticationFailedEvent $event) {
    if ($event->isSuspicious()) {
        // Handle potential brute force
        $securityService->flagSuspiciousActivity(
            $event->getClientIp(),
            $event->getUsername(),
            $event->getReason()
        );
    }
});
```

**Key Methods**:
- `isInvalidCredentials(): bool` - Check if credentials were invalid
- `isUserDisabled(): bool` - Check if user account is disabled
- `isSuspicious(): bool` - Check if this appears to be a brute force attempt

### SessionDestroyedEvent

**Triggered**: When user sessions are terminated

**Usage**: Session cleanup, security logging

### RateLimitExceededEvent

**Triggered**: When rate limits are exceeded

**Usage**: Security monitoring, adaptive rate limiting

**Note**: This event is now detailed in the [Security Events](#security-events) section as part of the framework vs application logging boundaries.

## Cache Events

### CacheHitEvent

**Triggered**: When a cache key is successfully retrieved

**Properties**:
```php
readonly string $key           // Cache key
readonly mixed $value         // Retrieved value
readonly array $tags          // Cache tags
readonly float $retrievalTime // Retrieval time in seconds
```

**Usage Example**:
```php
use Glueful\Events\Cache\CacheHitEvent;

$eventDispatcher->addListener(CacheHitEvent::class, function(CacheHitEvent $event) {
    // Monitor slow cache retrievals
    if ($event->isSlow(0.1)) {
        $logger->warning('Slow cache retrieval', [
            'key' => $event->getKey(),
            'time' => $event->getRetrievalTime(),
            'size' => $event->getValueSize()
        ]);
    }
});
```

**Key Methods**:
- `getValueSize(): int` - Get value size in bytes
- `isSlow(float $threshold = 0.1): bool` - Check if retrieval was slow

### CacheMissEvent

**Triggered**: When a cache key is not found

**Usage**: Cache miss analytics, optimization strategies

### CacheInvalidatedEvent

**Triggered**: When cache entries are invalidated

**Usage**: Cache management, debugging invalidation patterns

## Database Events

### QueryExecutedEvent

**Triggered**: When database queries are executed

**Properties**:
```php
readonly string $sql            // SQL query
readonly array $bindings       // Query bindings
readonly float $executionTime  // Execution time in seconds
readonly string $connectionName // Database connection name
readonly array $metadata       // Additional metadata
```

**Usage Example**:
```php
use Glueful\Events\Database\QueryExecutedEvent;

$eventDispatcher->addListener(QueryExecutedEvent::class, function(QueryExecutedEvent $event) {
    // Log slow queries
    if ($event->isSlow(1.0)) {
        $logger->warning('Slow database query detected', [
            'sql' => $event->getSql(),
            'execution_time' => $event->getExecutionTime(),
            'type' => $event->getQueryType(),
            'bindings' => $event->getBindings()
        ]);
    }
    
    // Track modifying queries
    if ($event->isModifying()) {
        $auditService->logDataModification($event->getFullQuery());
    }
});
```

**Key Methods**:
- `getFullQuery(): string` - Get query with bindings interpolated
- `isSlow(float $threshold = 1.0): bool` - Check if query is slow
- `getQueryType(): string` - Get query type (SELECT, INSERT, UPDATE, DELETE)
- `isModifying(): bool` - Check if query modifies data

### EntityCreatedEvent

**Triggered**: When database entities are created

**Usage**: Data synchronization, audit trails

### EntityUpdatedEvent

**Triggered**: When database entities are updated

**Usage**: Change tracking, audit trails

## HTTP Events

### RequestEvent

**Triggered**: When HTTP requests are received and processed

**Properties**:
```php
readonly Request $request  // Symfony HTTP request object
readonly array $metadata  // Additional request metadata
```

**Usage Example**:
```php
use Glueful\Events\Http\RequestEvent;

$eventDispatcher->addListener(RequestEvent::class, function(RequestEvent $event) {
    // Log API requests
    $logger->info('API request received', [
        'method' => $event->getMethod(),
        'uri' => $event->getUri(),
        'client_ip' => $event->getClientIp(),
        'user_agent' => $event->getUserAgent(),
        'is_secure' => $event->isSecure(),
        'is_ajax' => $event->isXmlHttpRequest()
    ]);
    
    // Security monitoring
    if (!$event->isSecure() && $event->getUri() !== '/health') {
        $securityService->flagInsecureRequest($event->getClientIp());
    }
});
```

**Key Methods**:
- `getMethod(): string` - Get HTTP method
- `getUri(): string` - Get request URI
- `getClientIp(): ?string` - Get client IP address
- `getUserAgent(): ?string` - Get user agent
- `getContentType(): ?string` - Get content type
- `isXmlHttpRequest(): bool` - Check if AJAX request
- `isSecure(): bool` - Check if HTTPS request

### ResponseEvent

**Triggered**: When HTTP responses are sent

**Usage**: Response logging, performance metrics

### ExceptionEvent

**Triggered**: When exceptions occur during request processing

**Usage**: Error handling, logging, debugging

### HttpAuthFailureEvent

**Triggered**: When HTTP-level authentication failures occur (framework logs protocol errors, application handles business logic)

**Properties**:
```php
readonly string $reason        // Failure reason (missing_authorization_header, malformed_jwt_token)
readonly Request $request      // HTTP request object
readonly ?string $tokenPrefix  // First 10 chars of token for debugging (null if no token)
```

**Usage Example**:
```php
use Glueful\Events\Http\HttpAuthFailureEvent;

$eventDispatcher->addListener(HttpAuthFailureEvent::class, function(HttpAuthFailureEvent $event) {
    // Application handles business context of auth failures
    $logger->info('Authentication attempt failed', [
        'reason' => $event->reason,
        'ip_address' => $event->request->getClientIp(),
        'endpoint' => $event->request->getPathInfo(),
        'user_agent' => $event->request->headers->get('User-Agent'),
        'timestamp' => now()->toISOString()
    ]);
    
    // Business logic: Track failed authentication patterns
    $this->trackFailedAuthPattern($event);
});
```

### HttpAuthSuccessEvent

**Triggered**: When HTTP-level authentication succeeds (framework validates protocol, application tracks business context)

**Properties**:
```php
readonly Request $request        // HTTP request object
readonly array $tokenMetadata   // Token validation metadata (e.g., token_prefix)
```

**Usage**: Business authentication tracking, user session analytics

### HttpClientFailureEvent

**Triggered**: When HTTP client infrastructure failures occur (connection timeouts, DNS issues, server errors)

**Properties**:
```php
readonly string $method        // HTTP method (GET, POST, etc.)
readonly string $url          // Target URL that failed
readonly \Throwable $exception // The exception that occurred
readonly string $failureType  // Type of failure (connection_failed, request_failed)
```

**Usage Example**:
```php
use Glueful\Events\Http\HttpClientFailureEvent;

$eventDispatcher->addListener(HttpClientFailureEvent::class, function(HttpClientFailureEvent $event) {
    // Application handles business context of external service failures
    $logger->error('External service failure', [
        'type' => 'integration',
        'service' => $this->getServiceNameFromUrl($event->url),
        'method' => $event->method,
        'url' => $event->url,
        'failure_type' => $event->failureType,
        'error' => $event->exception->getMessage(),
        'timestamp' => now()->toISOString()
    ]);
});
```

## Security Events

Framework emits these events for infrastructure/protocol security concerns. Applications should listen and implement business security logic.

### RateLimitExceededEvent

**Triggered**: When rate limits are exceeded (framework detects, application responds)

**Properties**:
```php
readonly string $ipAddress    // Client IP address
readonly string $endpoint     // Requested endpoint
readonly string $method       // HTTP method
readonly array $limits        // Rate limit configuration that was exceeded
readonly Request $request     // Full request object for additional context
```

**Usage Example**:
```php
use Glueful\Events\Security\RateLimitExceededEvent;

$eventDispatcher->addListener(RateLimitExceededEvent::class, function(RateLimitExceededEvent $event) {
    // Application handles business security response
    $logger->warning('Rate limit violation detected', [
        'ip_address' => $event->ipAddress,
        'endpoint' => $event->endpoint,
        'method' => $event->method,
        'limits' => $event->limits,
        'user_agent' => $event->request->headers->get('User-Agent'),
        'timestamp' => now()->toISOString()
    ]);
    
    // Business logic: Custom security responses
    if ($this->isSuspiciousActivity($event)) {
        $this->blacklistIp($event->ipAddress);
        $this->sendSecurityAlert($event);
    }
});
```

### CSRFViolationEvent

**Triggered**: When CSRF token validation fails (framework validates, application responds)

**Properties**:
```php
readonly string $reason    // Violation reason (missing_token, invalid_token, expired_token)
readonly Request $request  // HTTP request object
```

**Usage Example**:
```php
use Glueful\Events\Security\CSRFViolationEvent;

$eventDispatcher->addListener(CSRFViolationEvent::class, function(CSRFViolationEvent $event) {
    // Application handles business security logging
    $logger->error('CSRF violation detected', [
        'reason' => $event->reason,
        'ip_address' => $event->request->getClientIp(),
        'endpoint' => $event->request->getPathInfo(),
        'method' => $event->request->getMethod(),
        'user_agent' => $event->request->headers->get('User-Agent'),
        'referer' => $event->request->headers->get('Referer'),
        'timestamp' => now()->toISOString()
    ]);
    
    // Business response to CSRF attack
    $this->handleCSRFAttack($event);
});
```

### UnhandledException

**Triggered**: When unhandled exceptions occur (framework logs, application analyzes business impact)

**Properties**:
```php
readonly \Throwable $exception  // The unhandled exception
readonly array $context         // Additional context from exception handler
```

**Usage Example**:
```php
use Glueful\Events\Security\UnhandledException;

$eventDispatcher->addListener(UnhandledException::class, function(UnhandledException $event) {
    // Application analyzes exceptions for business/security implications
    if ($this->isBusinessCriticalException($event->exception)) {
        $logger->error('Business critical exception occurred', [
            'exception_type' => get_class($event->exception),
            'message' => $event->exception->getMessage(),
            'file' => $event->exception->getFile(),
            'line' => $event->exception->getLine(),
            'context' => $event->context,
            'timestamp' => now()->toISOString()
        ]);
        
        // Business logic: Handle critical failures
        $this->handleCriticalFailure($event);
    }
});
```

## Event Listeners

The framework includes built-in event listeners:

### CacheInvalidationListener

Handles automatic cache invalidation based on data changes.

### SecurityMonitoringListener

Monitors security-related events for threat detection.

### PerformanceMonitoringListener

Tracks performance metrics across the application.

## Extension Integration

Extensions can register event subscribers using the `ExtensionEventRegistry`:

### Creating Event Subscribers in Extensions

```php
// In your extension class
class MyExtension
{
    /**
     * Get event subscribers for this extension
     */
    public static function getEventSubscribers(): array
    {
        return [
            // Simple method mapping
            SessionCreatedEvent::class => 'onSessionCreated',
            
            // Method with priority
            QueryExecutedEvent::class => ['onQueryExecuted', 10],
            
            // Multiple listeners for same event
            AuthenticationFailedEvent::class => [
                ['onAuthFailed', 10],
                ['logAuthFailure', 5]
            ]
        ];
    }
    
    public function onSessionCreated(SessionCreatedEvent $event): void
    {
        // Handle session creation
    }
    
    public function onQueryExecuted(QueryExecutedEvent $event): void
    {
        // Handle query execution
    }
    
    public function onAuthFailed(AuthenticationFailedEvent $event): void
    {
        // Handle authentication failure
    }
    
    public function logAuthFailure(AuthenticationFailedEvent $event): void
    {
        // Additional logging
    }
}
```

### Event Subscriber Priorities

Higher priority listeners execute first:
- **10+**: Critical system operations
- **5-9**: Important business logic
- **0-4**: Logging and monitoring
- **Negative**: Cleanup operations

## Creating Custom Events

### Step 1: Create Event Class

```php
<?php

declare(strict_types=1);

namespace Glueful\Events\Custom;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Custom Business Event
 */
class OrderProcessedEvent extends Event
{
    public function __construct(
        private readonly string $orderId,
        private readonly array $orderData,
        private readonly float $processingTime,
        private readonly array $metadata = []
    ) {
    }

    public function getOrderId(): string
    {
        return $this->orderId;
    }

    public function getOrderData(): array
    {
        return $this->orderData;
    }

    public function getProcessingTime(): float
    {
        return $this->processingTime;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function wasSlowToProcess(float $threshold = 5.0): bool
    {
        return $this->processingTime > $threshold;
    }
}
```

### Step 2: Dispatch the Event

```php
use Symfony\Component\EventDispatcher\EventDispatcher;
use Glueful\Events\Custom\OrderProcessedEvent;

// In your service class
public function processOrder(array $orderData): void
{
    $startTime = microtime(true);
    
    // Process order logic here
    $orderId = $this->createOrder($orderData);
    
    $processingTime = microtime(true) - $startTime;
    
    // Dispatch event
    $event = new OrderProcessedEvent(
        $orderId,
        $orderData,
        $processingTime,
        ['user_id' => $this->getCurrentUserId()]
    );
    
    $this->eventDispatcher->dispatch($event);
}
```

### Step 3: Listen for the Event

```php
use Glueful\Events\Custom\OrderProcessedEvent;

$eventDispatcher->addListener(OrderProcessedEvent::class, function(OrderProcessedEvent $event) {
    // Send notification
    if ($event->wasSlowToProcess()) {
        $alertService->sendSlowProcessingAlert($event->getOrderId(), $event->getProcessingTime());
    }
    
    // Update analytics
    $analyticsService->recordOrderProcessing($event->getOrderData(), $event->getProcessingTime());
});
```

## Performance Considerations

### Event Performance Tips

1. **Keep listeners lightweight** - Avoid heavy processing in event listeners
2. **Use appropriate priorities** - Critical operations should have higher priority
3. **Consider async processing** - For heavy operations, queue them instead of processing synchronously
4. **Limit event data** - Only include necessary data in events to reduce memory usage

### Monitoring Event Performance

```php
$eventDispatcher->addListener('*', function($event) {
    $startTime = microtime(true);
    
    // Original event processing happens here
    
    $executionTime = microtime(true) - $startTime;
    
    if ($executionTime > 0.1) {
        $logger->warning('Slow event processing', [
            'event_class' => get_class($event),
            'execution_time' => $executionTime
        ]);
    }
});
```

## Best Practices

### 1. Event Design

- **Use readonly properties** for immutable events
- **Include relevant metadata** for debugging and analytics
- **Provide helper methods** for common checks (e.g., `isSlow()`, `isModifying()`)
- **Use descriptive class names** ending in "Event"

### 2. Event Dispatching

- **Dispatch at the right time** - After the action is complete
- **Include timing information** for performance monitoring
- **Add security context** when relevant (IP, user agent, user ID)

### 3. Event Listening

- **Keep listeners focused** - One responsibility per listener
- **Handle exceptions gracefully** - Don't let listeners break the main flow
- **Use appropriate log levels** - Debug for frequent events, warning for issues
- **Consider performance impact** - Avoid slow operations in listeners

### 4. Extension Integration

- **Use static methods** for event subscriber registration
- **Provide clear documentation** for extension events
- **Test event integration** thoroughly
- **Handle missing events gracefully** in extensions

### 5. Framework vs Application Logging

- **Framework Events**: Listen to framework security/infrastructure events for business responses
- **Application Logging**: Implement business-specific logging in event listeners
- **Separation of Concerns**: Framework logs protocol/infrastructure, applications log business logic
- **Event-Driven Security**: Use security events (rate limits, CSRF, auth failures) for custom business responses

## Example: Complete Event Flow

Here's a complete example showing how events work throughout a user login:

```php
// 1. Authentication attempt triggers RequestEvent
$requestEvent = new RequestEvent($request, ['start_time' => microtime(true)]);
$eventDispatcher->dispatch($requestEvent);

// 2. If authentication fails
if (!$authResult->isSuccess()) {
    $authFailedEvent = new AuthenticationFailedEvent(
        $username,
        'invalid_credentials',
        $request->getClientIp(),
        $request->headers->get('User-Agent')
    );
    $eventDispatcher->dispatch($authFailedEvent);
    return;
}

// 3. If authentication succeeds, session is created
$sessionEvent = new SessionCreatedEvent(
    $sessionData,
    $tokens,
    ['login_method' => 'password', 'remember_me' => $rememberMe]
);
$eventDispatcher->dispatch($sessionEvent);

// 4. Database queries trigger QueryExecutedEvent
// (automatically dispatched by QueryLogger)

// 5. Cache operations trigger CacheHitEvent/CacheMissEvent
// (automatically dispatched by CacheStore)

// 6. Response sent triggers ResponseEvent
$responseEvent = new ResponseEvent($response, ['processing_time' => $processingTime]);
$eventDispatcher->dispatch($responseEvent);
```

This documentation provides a comprehensive guide to the Glueful event system. For specific implementation details, refer to the event class files in `/api/Events/`.