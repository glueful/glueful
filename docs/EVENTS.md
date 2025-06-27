# Glueful Event System Documentation

The Glueful framework includes a comprehensive event system built on Symfony EventDispatcher, providing extensible hooks throughout the application lifecycle for monitoring, logging, and custom functionality.

## Table of Contents

- [Overview](#overview)
- [Event Categories](#event-categories)
- [Authentication Events](#authentication-events)
- [Cache Events](#cache-events)
- [Database Events](#database-events)
- [HTTP Events](#http-events)
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

- 17 built-in event classes
- Symfony EventDispatcher integration
- Extension event subscriber system
- Performance monitoring capabilities
- Security-focused event data
- Type-safe PHP 8.2+ implementation

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