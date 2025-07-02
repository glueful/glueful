# Glueful Event System Documentation

## Overview

The Glueful framework provides a comprehensive event system built on Symfony EventDispatcher, enabling decoupled communication between framework components and application code. The system clearly separates framework infrastructure concerns from application business logic through well-defined event boundaries.

## Table of Contents

- [Architecture](#architecture)
- [Framework vs Application Boundaries](#framework-vs-application-boundaries)
- [Core Event Categories](#core-event-categories)
- [Authentication Events](#authentication-events)
- [Security Events](#security-events)
- [Session Analytics Events](#session-analytics-events)
- [HTTP Events](#http-events)
- [Database Events](#database-events)
- [Cache Events](#cache-events)
- [Logging System Integration](#logging-system-integration)
- [Event Listeners](#event-listeners)
- [Creating Custom Events](#creating-custom-events)
- [Extension Integration](#extension-integration)
- [Performance Monitoring](#performance-monitoring)
- [Best Practices](#best-practices)
- [Command Reference](#command-reference)

## Architecture

### Event Flow

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│  Framework      │───▶│  Event System    │───▶│  Application    │
│  Components     │    │  (Dispatcher)    │    │  Listeners      │
└─────────────────┘    └──────────────────┘    └─────────────────┘
                              │
                              ▼
                       ┌─────────────────┐
                       │  Extensions     │
                       │  Listeners      │
                       └─────────────────┘
```

### Key Features

- **Type-safe events** with PHP 8.2+ readonly properties
- **Framework boundary separation** for clear responsibility division
- **Performance monitoring** built into event system
- **Extension integration** with priority-based listeners
- **Logging system integration** with structured context
- **Session analytics** for user behavior tracking
- **Security event monitoring** for threat detection

## Framework vs Application Boundaries

### Framework Responsibilities

The framework emits events for **infrastructure and protocol concerns**:

- HTTP protocol validation (auth headers, CSRF tokens)
- Rate limiting enforcement
- Database query execution
- Cache operations
- Session lifecycle management
- Security policy violations

### Application Responsibilities

Applications listen to framework events and implement **business logic responses**:

- User behavior analytics
- Business rule enforcement
- Custom security policies
- Audit trail creation
- Notification dispatch
- Integration with external services

### Boundary Example

```php
// Framework: Detects rate limit violation (infrastructure concern)
$event = new RateLimitExceededEvent($ip, $endpoint, $limits);
$dispatcher->dispatch($event);

// Application: Responds with business logic
$eventDispatcher->addListener(RateLimitExceededEvent::class, function($event) {
    // Business decision: Should we block this user?
    if ($this->threatAnalyzer->isSuspicious($event->getClientIp())) {
        $this->userManager->temporaryBlock($event->getUserId());
        $this->notifications->alertSecurityTeam($event);
    }
});
```

## Core Event Categories

### 1. Authentication Events (`Glueful\Events\Auth`)
- User session lifecycle
- Authentication attempts and failures  
- Token management
- Session analytics

### 2. Security Events (`Glueful\Events\Security`)
- Rate limiting violations
- CSRF protection failures
- Security policy violations
- Threat detection

### 3. HTTP Events (`Glueful\Events\Http`)
- Request/response lifecycle
- HTTP authentication
- Exception handling
- Client interaction tracking

### 4. Database Events (`Glueful\Events\Database`)
- Query execution monitoring
- Entity lifecycle management
- Performance tracking
- Data modification auditing

### 5. Cache Events (`Glueful\Events\Cache`)
- Cache operations (hit/miss/invalidation)
- Performance monitoring
- Cache strategy optimization

### 6. Session Analytics Events (`Glueful\Events\Analytics`)
- User behavior tracking
- Session pattern analysis
- Usage statistics
- Performance metrics

## Authentication Events

### UserAuthenticatedEvent

**When**: User successfully authenticates
**Purpose**: Track successful authentications for analytics and security

```php
namespace Glueful\Events\Auth;

class UserAuthenticatedEvent extends Event
{
    public function __construct(
        public readonly string $userId,
        public readonly string $username,
        public readonly string $authMethod, // password, oauth, etc.
        public readonly array $sessionData,
        public readonly ?string $clientIp = null,
        public readonly ?string $userAgent = null,
        public readonly array $metadata = []
    ) {}
}
```

**Usage Example**:
```php
$dispatcher->addListener(UserAuthenticatedEvent::class, function(UserAuthenticatedEvent $event) {
    // Business logic: Track login patterns
    $this->analyticsService->recordLogin([
        'user_id' => $event->userId,
        'method' => $event->authMethod,
        'ip' => $event->clientIp,
        'timestamp' => now()->toISOString()
    ]);
    
    // Security: Check for unusual login patterns
    if ($this->securityAnalyzer->isUnusualLogin($event)) {
        $this->notificationService->sendSecurityAlert($event->userId);
    }
});
```

### AuthenticationFailedEvent

**When**: Authentication attempt fails
**Purpose**: Security monitoring and user experience improvement

```php
namespace Glueful\Events\Auth;

class AuthenticationFailedEvent extends Event
{
    public function __construct(
        public readonly string $attemptedUsername,
        public readonly string $failureReason,
        public readonly ?string $clientIp = null,
        public readonly ?string $userAgent = null,
        public readonly array $context = []
    ) {}
    
    public function isCredentialFailure(): bool
    {
        return $this->failureReason === 'invalid_credentials';
    }
    
    public function isAccountLocked(): bool
    {
        return $this->failureReason === 'account_locked';
    }
    
    public function isPotentialBruteForce(): bool
    {
        return isset($this->context['attempt_count']) && 
               $this->context['attempt_count'] > 3;
    }
}
```

### SessionCreatedEvent

**When**: New user session is established
**Purpose**: Session analytics and initialization

```php
namespace Glueful\Events\Auth;

class SessionCreatedEvent extends Event
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $userId,
        public readonly array $sessionData,
        public readonly array $tokens,
        public readonly bool $rememberMe = false,
        public readonly array $metadata = []
    ) {}
    
    public function getAccessToken(): string
    {
        return $this->tokens['access_token'];
    }
    
    public function getRefreshToken(): ?string
    {
        return $this->tokens['refresh_token'] ?? null;
    }
}
```

### SessionDestroyedEvent

**When**: User session is terminated
**Purpose**: Cleanup and analytics

```php
namespace Glueful\Events\Auth;

class SessionDestroyedEvent extends Event
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $userId,
        public readonly string $reason, // logout, timeout, forced
        public readonly int $duration, // session duration in seconds
        public readonly array $metadata = []
    ) {}
    
    public function wasForced(): bool
    {
        return $this->reason === 'forced';
    }
    
    public function wasTimeout(): bool
    {
        return $this->reason === 'timeout';
    }
}
```

## Security Events

### RateLimitExceededEvent

**When**: Rate limit is exceeded
**Purpose**: Security monitoring and adaptive responses

```php
namespace Glueful\Events\Security;

class RateLimitExceededEvent extends Event
{
    public function __construct(
        public readonly string $clientIp,
        public readonly string $endpoint,
        public readonly string $method,
        public readonly array $limitConfig,
        public readonly int $currentCount,
        public readonly Request $request,
        public readonly ?string $userId = null
    ) {}
    
    public function getExcessPercentage(): float
    {
        return ($this->currentCount / $this->limitConfig['max_requests']) * 100;
    }
    
    public function isSeverViolation(): bool
    {
        return $this->getExcessPercentage() > 200;
    }
}
```

**Usage Example**:
```php
$dispatcher->addListener(RateLimitExceededEvent::class, function(RateLimitExceededEvent $event) {
    // Progressive response based on violation severity
    if ($event->isSeverViolation()) {
        // Severe violation: Temporary IP block
        $this->securityManager->blockIP($event->clientIp, '1 hour');
        $this->alertService->criticalAlert('Severe rate limit violation', $event);
    } else {
        // Minor violation: Increase rate limit temporarily
        $this->rateLimiter->penaltyMode($event->clientIp, '10 minutes');
    }
    
    // Analytics
    $this->analyticsService->recordSecurityEvent('rate_limit_exceeded', [
        'ip' => $event->clientIp,
        'endpoint' => $event->endpoint,
        'severity' => $event->getExcessPercentage()
    ]);
});
```

### CSRFViolationEvent

**When**: CSRF token validation fails
**Purpose**: Security monitoring and attack prevention

```php
namespace Glueful\Events\Security;

class CSRFViolationEvent extends Event
{
    public function __construct(
        public readonly string $reason, // missing_token, invalid_token, expired_token
        public readonly Request $request,
        public readonly ?string $expectedToken = null,
        public readonly ?string $providedToken = null
    ) {}
    
    public function isMissingToken(): bool
    {
        return $this->reason === 'missing_token';
    }
    
    public function isExpiredToken(): bool
    {
        return $this->reason === 'expired_token';
    }
}
```

### SuspiciousActivityDetectedEvent

**When**: Anomalous behavior patterns are detected
**Purpose**: Proactive threat detection

```php
namespace Glueful\Events\Security;

class SuspiciousActivityDetectedEvent extends Event
{
    public function __construct(
        public readonly string $activityType,
        public readonly string $clientIp,
        public readonly array $indicators,
        public readonly float $riskScore,
        public readonly ?string $userId = null,
        public readonly array $context = []
    ) {}
    
    public function isHighRisk(): bool
    {
        return $this->riskScore >= 0.8;
    }
    
    public function getCriticalIndicators(): array
    {
        return array_filter($this->indicators, fn($i) => $i['severity'] === 'critical');
    }
}
```

## Session Analytics Events

### SessionActivityEvent

**When**: User performs actions during session
**Purpose**: User behavior analytics and experience optimization

```php
namespace Glueful\Events\Analytics;

class SessionActivityEvent extends Event
{
    public function __construct(
        public readonly string $sessionId,
        public readonly string $userId,
        public readonly string $action,
        public readonly string $resource,
        public readonly array $parameters = [],
        public readonly float $responseTime = 0.0,
        public readonly array $metadata = []
    ) {}
    
    public function isSlowResponse(): bool
    {
        return $this->responseTime > 2.0;
    }
    
    public function isErrorResponse(): bool
    {
        return isset($this->metadata['status_code']) && 
               $this->metadata['status_code'] >= 400;
    }
}
```

**Usage Example**:
```php
$dispatcher->addListener(SessionActivityEvent::class, function(SessionActivityEvent $event) {
    // User behavior analytics
    $this->analyticsService->recordUserAction([
        'user_id' => $event->userId,
        'action' => $event->action,
        'resource' => $event->resource,
        'response_time' => $event->responseTime,
        'timestamp' => now()->toISOString()
    ]);
    
    // Performance monitoring
    if ($event->isSlowResponse()) {
        $this->performanceMonitor->recordSlowAction($event);
    }
    
    // User experience optimization
    if ($event->isErrorResponse()) {
        $this->uxAnalyzer->recordErrorPattern($event);
    }
});
```

### SessionPatternEvent

**When**: Session usage patterns are analyzed
**Purpose**: Advanced analytics and personalization

```php
namespace Glueful\Events\Analytics;

class SessionPatternEvent extends Event
{
    public function __construct(
        public readonly string $userId,
        public readonly string $patternType, // usage, navigation, preference
        public readonly array $pattern,
        public readonly float $confidence,
        public readonly array $recommendations = []
    ) {}
    
    public function isHighConfidence(): bool
    {
        return $this->confidence >= 0.85;
    }
}
```

## HTTP Events

### RequestReceivedEvent

**When**: HTTP request is received and parsed
**Purpose**: Request tracking and analysis

```php
namespace Glueful\Events\Http;

class RequestReceivedEvent extends Event
{
    public function __construct(
        public readonly Request $request,
        public readonly float $timestamp,
        public readonly array $metadata = []
    ) {}
    
    public function isAPIRequest(): bool
    {
        return str_starts_with($this->request->getPathInfo(), '/api/');
    }
    
    public function isSecure(): bool
    {
        return $this->request->isSecure();
    }
    
    public function getEndpoint(): string
    {
        return $this->request->getMethod() . ' ' . $this->request->getPathInfo();
    }
}
```

### ResponseSentEvent

**When**: HTTP response is sent to client
**Purpose**: Performance monitoring and analytics

```php
namespace Glueful\Events\Http;

class ResponseSentEvent extends Event
{
    public function __construct(
        public readonly Request $request,
        public readonly Response $response,
        public readonly float $processingTime,
        public readonly array $metrics = []
    ) {}
    
    public function isError(): bool
    {
        return $this->response->getStatusCode() >= 400;
    }
    
    public function isSlowResponse(): bool
    {
        return $this->processingTime > 1.0;
    }
    
    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }
}
```

## Database Events

### QueryExecutedEvent

**When**: Database query is executed
**Purpose**: Performance monitoring and audit

```php
namespace Glueful\Events\Database;

class QueryExecutedEvent extends Event
{
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
        public readonly float $executionTime,
        public readonly string $connectionName = 'default',
        public readonly array $metadata = []
    ) {}
    
    public function isSlow(float $threshold = 1.0): bool
    {
        return $this->executionTime > $threshold;
    }
    
    public function isModifying(): bool
    {
        $query = strtoupper(trim($this->sql));
        return str_starts_with($query, 'INSERT') ||
               str_starts_with($query, 'UPDATE') ||
               str_starts_with($query, 'DELETE');
    }
    
    public function getQueryType(): string
    {
        return strtoupper(explode(' ', trim($this->sql))[0]);
    }
    
    public function getAffectedTable(): ?string
    {
        // Extract table name from query
        if (preg_match('/(?:FROM|INTO|UPDATE|DELETE FROM)\s+`?(\w+)`?/i', $this->sql, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
```

**Usage Example**:
```php
$dispatcher->addListener(QueryExecutedEvent::class, function(QueryExecutedEvent $event) {
    // Performance monitoring
    if ($event->isSlow()) {
        $this->performanceLogger->warning('Slow query detected', [
            'query' => $event->sql,
            'execution_time' => $event->executionTime,
            'table' => $event->getAffectedTable()
        ]);
    }
    
    // Audit trail for data modifications
    if ($event->isModifying()) {
        $this->auditService->logDataChange([
            'operation' => $event->getQueryType(),
            'table' => $event->getAffectedTable(),
            'query' => $event->sql,
            'timestamp' => now()->toISOString()
        ]);
    }
    
    // Query analytics
    $this->queryAnalytics->recordQuery($event);
});
```

### EntityLifecycleEvent

**When**: Entity is created, updated, or deleted
**Purpose**: Business logic triggers and audit

```php
namespace Glueful\Events\Database;

class EntityLifecycleEvent extends Event
{
    public function __construct(
        public readonly string $action, // created, updated, deleted
        public readonly string $entityType,
        public readonly string $entityId,
        public readonly array $entityData = [],
        public readonly array $changes = [],
        public readonly ?string $userId = null
    ) {}
    
    public function isCreated(): bool
    {
        return $this->action === 'created';
    }
    
    public function isUpdated(): bool
    {
        return $this->action === 'updated';
    }
    
    public function isDeleted(): bool
    {
        return $this->action === 'deleted';
    }
    
    public function hasFieldChanged(string $field): bool
    {
        return isset($this->changes[$field]);
    }
}
```

## Cache Events

### CacheOperationEvent

**When**: Cache operations are performed
**Purpose**: Performance optimization and monitoring

```php
namespace Glueful\Events\Cache;

class CacheOperationEvent extends Event
{
    public function __construct(
        public readonly string $operation, // hit, miss, set, delete, invalidate
        public readonly string $key,
        public readonly ?string $value = null,
        public readonly float $operationTime = 0.0,
        public readonly array $tags = [],
        public readonly array $metadata = []
    ) {}
    
    public function isHit(): bool
    {
        return $this->operation === 'hit';
    }
    
    public function isMiss(): bool
    {
        return $this->operation === 'miss';
    }
    
    public function isSlow(float $threshold = 0.1): bool
    {
        return $this->operationTime > $threshold;
    }
    
    public function getValueSize(): int
    {
        return $this->value ? strlen($this->value) : 0;
    }
}
```

**Usage Example**:
```php
$dispatcher->addListener(CacheOperationEvent::class, function(CacheOperationEvent $event) {
    // Cache performance monitoring
    if ($event->isSlow()) {
        $this->performanceLogger->warning('Slow cache operation', [
            'operation' => $event->operation,
            'key' => $event->key,
            'time' => $event->operationTime,
            'size' => $event->getValueSize()
        ]);
    }
    
    // Cache hit rate analytics
    $this->cacheAnalytics->recordOperation([
        'operation' => $event->operation,
        'key_pattern' => $this->extractPattern($event->key),
        'hit' => $event->isHit(),
        'timestamp' => now()->toISOString()
    ]);
    
    // Cache strategy optimization
    if ($event->isMiss()) {
        $this->cacheOptimizer->analyzeMissPattern($event->key);
    }
});
```

## Logging System Integration

### Structured Event Logging

The framework provides seamless integration with the logging system through event-driven structured logging:

```php
namespace Glueful\Logging;

class EventLoggerListener
{
    public function __construct(
        private LoggerInterface $logger,
        private ContextEnricher $contextEnricher
    ) {}
    
    public function logAuthenticationEvent(UserAuthenticatedEvent $event): void
    {
        $context = $this->contextEnricher->enrich([
            'event_type' => 'authentication',
            'user_id' => $event->userId,
            'auth_method' => $event->authMethod,
            'client_ip' => $event->clientIp,
            'user_agent' => $event->userAgent,
            'session_data' => $event->sessionData
        ]);
        
        $this->logger->info('User authenticated successfully', $context);
    }
    
    public function logSecurityEvent(RateLimitExceededEvent $event): void
    {
        $context = $this->contextEnricher->enrich([
            'event_type' => 'security_violation',
            'violation_type' => 'rate_limit_exceeded',
            'client_ip' => $event->clientIp,
            'endpoint' => $event->endpoint,
            'current_count' => $event->currentCount,
            'limit_config' => $event->limitConfig,
            'severity' => $event->isSeverViolation() ? 'high' : 'medium'
        ]);
        
        $this->logger->warning('Rate limit exceeded', $context);
    }
    
    public function logPerformanceEvent(QueryExecutedEvent $event): void
    {
        if ($event->isSlow()) {
            $context = $this->contextEnricher->enrich([
                'event_type' => 'performance',
                'component' => 'database',
                'query_type' => $event->getQueryType(),
                'execution_time' => $event->executionTime,
                'affected_table' => $event->getAffectedTable(),
                'query_hash' => hash('sha256', $event->sql)
            ]);
            
            $this->logger->warning('Slow database query detected', $context);
        }
    }
}
```

### Context Enrichment

```php
namespace Glueful\Logging;

class ContextEnricher
{
    public function enrich(array $context): array
    {
        return array_merge($context, [
            'timestamp' => now()->toISOString(),
            'request_id' => $this->getRequestId(),
            'trace_id' => $this->getTraceId(),
            'user_session' => $this->getCurrentSession(),
            'environment' => config('app.env'),
            'version' => config('app.version')
        ]);
    }
    
    private function getRequestId(): ?string
    {
        return request()?->headers->get('X-Request-ID');
    }
    
    private function getTraceId(): ?string
    {
        return request()?->headers->get('X-Trace-ID');
    }
    
    private function getCurrentSession(): ?array
    {
        $session = session();
        return $session ? [
            'id' => $session->getId(),
            'user_id' => $session->get('user_id')
        ] : null;
    }
}
```

## Event Listeners

### Built-in Framework Listeners

#### SecurityMonitoringListener

```php
namespace Glueful\Events\Listeners;

class SecurityMonitoringListener
{
    public function __construct(
        private SecurityAnalyzer $analyzer,
        private ThreatDetector $threatDetector,
        private AlertService $alertService
    ) {}
    
    public function onAuthenticationFailed(AuthenticationFailedEvent $event): void
    {
        $this->analyzer->recordFailedAttempt($event);
        
        if ($this->threatDetector->isBruteForcePattern($event)) {
            $this->alertService->securityAlert('brute_force_detected', $event);
        }
    }
    
    public function onRateLimitExceeded(RateLimitExceededEvent $event): void
    {
        $this->analyzer->recordRateLimitViolation($event);
        
        if ($event->isSeverViolation()) {
            $this->alertService->criticalAlert('severe_rate_limit_violation', $event);
        }
    }
    
    public function onSuspiciousActivity(SuspiciousActivityDetectedEvent $event): void
    {
        if ($event->isHighRisk()) {
            $this->alertService->securityAlert('high_risk_activity', $event);
            $this->analyzer->initiateDetailedAnalysis($event);
        }
    }
}
```

#### PerformanceMonitoringListener

```php
namespace Glueful\Events\Listeners;

class PerformanceMonitoringListener
{
    public function __construct(
        private MetricsCollector $metrics,
        private PerformanceAnalyzer $analyzer,
        private AlertService $alertService
    ) {}
    
    public function onQueryExecuted(QueryExecutedEvent $event): void
    {
        $this->metrics->recordQueryMetrics([
            'execution_time' => $event->executionTime,
            'query_type' => $event->getQueryType(),
            'table' => $event->getAffectedTable()
        ]);
        
        if ($event->isSlow()) {
            $this->analyzer->analyzeSlowQuery($event);
        }
    }
    
    public function onResponseSent(ResponseSentEvent $event): void
    {
        $this->metrics->recordResponseMetrics([
            'processing_time' => $event->processingTime,
            'status_code' => $event->getStatusCode(),
            'endpoint' => $event->request->getPathInfo()
        ]);
        
        if ($event->isSlowResponse()) {
            $this->analyzer->analyzeSlowResponse($event);
        }
    }
    
    public function onCacheOperation(CacheOperationEvent $event): void
    {
        $this->metrics->recordCacheMetrics([
            'operation' => $event->operation,
            'hit' => $event->isHit(),
            'operation_time' => $event->operationTime
        ]);
    }
}
```

#### AnalyticsListener

```php
namespace Glueful\Events\Listeners;

class AnalyticsListener
{
    public function __construct(
        private AnalyticsService $analytics,
        private UserBehaviorTracker $behaviorTracker
    ) {}
    
    public function onUserAuthenticated(UserAuthenticatedEvent $event): void
    {
        $this->analytics->recordEvent('user_login', [
            'user_id' => $event->userId,
            'method' => $event->authMethod,
            'timestamp' => now()->toISOString()
        ]);
    }
    
    public function onSessionActivity(SessionActivityEvent $event): void
    {
        $this->behaviorTracker->recordActivity([
            'user_id' => $event->userId,
            'action' => $event->action,
            'resource' => $event->resource,
            'response_time' => $event->responseTime
        ]);
    }
    
    public function onSessionDestroyed(SessionDestroyedEvent $event): void
    {
        $this->analytics->recordEvent('session_ended', [
            'user_id' => $event->userId,
            'duration' => $event->duration,
            'reason' => $event->reason
        ]);
    }
}
```

### Custom Application Listeners

```php
// In your application
class MyApplicationEventListener
{
    public function __construct(
        private BusinessAnalytics $analytics,
        private NotificationService $notifications
    ) {}
    
    public function onUserAuthenticated(UserAuthenticatedEvent $event): void
    {
        // Business-specific login tracking
        $this->analytics->userLoggedIn($event->userId);
        
        // Send welcome notification for first-time users
        if ($this->isFirstLogin($event->userId)) {
            $this->notifications->sendWelcomeMessage($event->userId);
        }
    }
    
    public function onEntityCreated(EntityLifecycleEvent $event): void
    {
        // Business workflow triggers
        if ($event->entityType === 'order' && $event->isCreated()) {
            $this->processNewOrder($event->entityId, $event->entityData);
        }
    }
    
    private function isFirstLogin(string $userId): bool
    {
        return $this->analytics->getLoginCount($userId) === 1;
    }
    
    private function processNewOrder(string $orderId, array $orderData): void
    {
        // Business logic for new orders
        $this->notifications->notifyWarehouse($orderId);
        $this->analytics->recordSale($orderData);
    }
}
```

## Creating Custom Events

### Step 1: Define Event Class

```php
<?php

declare(strict_types=1);

namespace App\Events;

use Symfony\Contracts\EventDispatcher\Event;

class OrderShippedEvent extends Event
{
    public function __construct(
        public readonly string $orderId,
        public readonly string $customerId,
        public readonly string $trackingNumber,
        public readonly string $carrier,
        public readonly array $items,
        public readonly array $shippingAddress,
        public readonly float $shippingCost,
        public readonly array $metadata = []
    ) {}
    
    public function getItemCount(): int
    {
        return count($this->items);
    }
    
    public function isExpressShipping(): bool
    {
        return ($this->metadata['shipping_method'] ?? '') === 'express';
    }
    
    public function isInternational(): bool
    {
        return ($this->shippingAddress['country'] ?? 'US') !== 'US';
    }
}
```

### Step 2: Dispatch Event

```php
namespace App\Services;

class OrderService
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher
    ) {}
    
    public function shipOrder(string $orderId): void
    {
        $order = $this->getOrder($orderId);
        
        // Ship the order
        $trackingNumber = $this->shippingProvider->ship($order);
        
        // Update order status
        $this->updateOrderStatus($orderId, 'shipped');
        
        // Dispatch event
        $event = new OrderShippedEvent(
            orderId: $orderId,
            customerId: $order['customer_id'],
            trackingNumber: $trackingNumber,
            carrier: $order['shipping_carrier'],
            items: $order['items'],
            shippingAddress: $order['shipping_address'],
            shippingCost: $order['shipping_cost'],
            metadata: [
                'shipping_method' => $order['shipping_method'],
                'shipped_at' => now()->toISOString()
            ]
        );
        
        $this->eventDispatcher->dispatch($event);
    }
}
```

### Step 3: Create Listeners

```php
// Service for handling shipping notifications
class ShippingNotificationService
{
    public function onOrderShipped(OrderShippedEvent $event): void
    {
        // Send tracking email to customer
        $this->emailService->sendTrackingEmail(
            $event->customerId,
            $event->orderId,
            $event->trackingNumber,
            $event->carrier
        );
        
        // Send SMS for express shipping
        if ($event->isExpressShipping()) {
            $this->smsService->sendShippingAlert(
                $event->customerId,
                $event->trackingNumber
            );
        }
    }
}

// Analytics service
class ShippingAnalyticsService
{
    public function onOrderShipped(OrderShippedEvent $event): void
    {
        $this->analytics->recordShipping([
            'order_id' => $event->orderId,
            'carrier' => $event->carrier,
            'item_count' => $event->getItemCount(),
            'shipping_cost' => $event->shippingCost,
            'is_express' => $event->isExpressShipping(),
            'is_international' => $event->isInternational(),
            'timestamp' => now()->toISOString()
        ]);
    }
}
```

## Extension Integration

### Registering Event Listeners in Extensions

```php
namespace Glueful\Extensions\MyExtension;

use Glueful\Core\Extension\BaseExtension;

class Extension extends BaseExtension
{
    public function boot(Container $container): void
    {
        // Register event listeners
        $this->registerEventListeners($container);
    }
    
    protected function registerEventListeners(Container $container): void
    {
        $dispatcher = $container->get(EventDispatcherInterface::class);
        
        // Register listeners with priority
        $dispatcher->addListener(UserAuthenticatedEvent::class, [$this, 'onUserAuthenticated'], 10);
        $dispatcher->addListener(QueryExecutedEvent::class, [$this, 'onQueryExecuted'], 5);
        $dispatcher->addListener(RateLimitExceededEvent::class, [$this, 'onRateLimitExceeded'], 100);
    }
    
    public function onUserAuthenticated(UserAuthenticatedEvent $event): void
    {
        // Extension-specific logic
        $this->trackUserLogin($event);
    }
    
    public function onQueryExecuted(QueryExecutedEvent $event): void
    {
        // Extension-specific database monitoring
        if ($event->isSlow()) {
            $this->alertSlowQuery($event);
        }
    }
    
    public function onRateLimitExceeded(RateLimitExceededEvent $event): void
    {
        // Extension-specific rate limit handling
        $this->handleRateLimitViolation($event);
    }
}
```

### Event Subscriber Pattern

```php
namespace Glueful\Extensions\MyExtension;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MyEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            UserAuthenticatedEvent::class => [
                ['onUserAuthenticated', 10],
                ['logAuthentication', 0]
            ],
            QueryExecutedEvent::class => 'onQueryExecuted',
            RateLimitExceededEvent::class => ['onRateLimitExceeded', 100]
        ];
    }
    
    public function onUserAuthenticated(UserAuthenticatedEvent $event): void
    {
        // Handle authentication
    }
    
    public function logAuthentication(UserAuthenticatedEvent $event): void
    {
        // Log authentication
    }
    
    public function onQueryExecuted(QueryExecutedEvent $event): void
    {
        // Handle query execution
    }
    
    public function onRateLimitExceeded(RateLimitExceededEvent $event): void
    {
        // Handle rate limit exceeded
    }
}
```

## Performance Monitoring

### Event Performance Metrics

```php
namespace Glueful\Events\Monitoring;

class EventPerformanceMonitor
{
    private array $metrics = [];
    
    public function startTiming(string $eventClass): void
    {
        $this->metrics[$eventClass]['start'] = microtime(true);
    }
    
    public function endTiming(string $eventClass): void
    {
        if (isset($this->metrics[$eventClass]['start'])) {
            $duration = microtime(true) - $this->metrics[$eventClass]['start'];
            $this->metrics[$eventClass]['durations'][] = $duration;
            
            if ($duration > 0.1) { // 100ms threshold
                $this->logSlowEventProcessing($eventClass, $duration);
            }
        }
    }
    
    public function getAverageTime(string $eventClass): float
    {
        $durations = $this->metrics[$eventClass]['durations'] ?? [];
        return count($durations) > 0 ? array_sum($durations) / count($durations) : 0.0;
    }
    
    private function logSlowEventProcessing(string $eventClass, float $duration): void
    {
        logger()->warning('Slow event processing detected', [
            'event_class' => $eventClass,
            'duration' => $duration,
            'threshold' => 0.1
        ]);
    }
}
```

### Memory Usage Monitoring

```php
namespace Glueful\Events\Monitoring;

class EventMemoryMonitor
{
    private int $baselineMemory;
    
    public function __construct()
    {
        $this->baselineMemory = memory_get_usage(true);
    }
    
    public function checkMemoryUsage(string $eventClass): void
    {
        $currentMemory = memory_get_usage(true);
        $memoryIncrease = $currentMemory - $this->baselineMemory;
        
        // Alert if memory increase is significant (> 10MB)
        if ($memoryIncrease > 10 * 1024 * 1024) {
            logger()->warning('High memory usage during event processing', [
                'event_class' => $eventClass,
                'memory_increase' => $memoryIncrease,
                'current_memory' => $currentMemory,
                'peak_memory' => memory_get_peak_usage(true)
            ]);
        }
    }
}
```

## Best Practices

### 1. Event Design

**✅ Good Event Design**:
```php
class UserProfileUpdatedEvent extends Event
{
    public function __construct(
        public readonly string $userId,
        public readonly array $changes,
        public readonly array $previousData,
        public readonly ?string $updatedBy = null,
        public readonly array $metadata = []
    ) {}
    
    public function hasEmailChanged(): bool
    {
        return isset($this->changes['email']);
    }
    
    public function wasProfilePictureUpdated(): bool
    {
        return isset($this->changes['profile_picture']);
    }
}
```

**❌ Poor Event Design**:
```php
class UserEvent extends Event
{
    public $data; // Not readonly, not typed
    public $action; // Unclear what this represents
    
    public function __construct($data, $action)
    {
        $this->data = $data;
        $this->action = $action;
    }
}
```

### 2. Listener Implementation

**✅ Good Listener**:
```php
class UserNotificationListener
{
    public function onUserProfileUpdated(UserProfileUpdatedEvent $event): void
    {
        try {
            if ($event->hasEmailChanged()) {
                $this->sendEmailChangeConfirmation($event->userId);
            }
            
            if ($event->wasProfilePictureUpdated()) {
                $this->updateProfilePictureCache($event->userId);
            }
        } catch (Exception $e) {
            logger()->error('Failed to process user profile update', [
                'user_id' => $event->userId,
                'error' => $e->getMessage()
            ]);
            // Don't re-throw - listener failures shouldn't break main flow
        }
    }
}
```

**❌ Poor Listener**:
```php
class BadListener
{
    public function handleEvent($event): void
    {
        // No type hints
        // Doing too much in one listener
        $this->sendEmail($event->data);
        $this->updateDatabase($event->data);
        $this->callExternalAPI($event->data);
        $this->generateReport($event->data);
        // No error handling
    }
}
```

### 3. Framework vs Application Separation

**✅ Proper Separation**:
```php
// Framework: Detects and reports security violation
class SecurityMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if ($this->rateLimiter->isExceeded($request->getClientIp())) {
            $event = new RateLimitExceededEvent(/* ... */);
            $this->dispatcher->dispatch($event);
            return new Response('Rate limit exceeded', 429);
        }
        
        return $next($request);
    }
}

// Application: Responds with business logic
class BusinessSecurityListener
{
    public function onRateLimitExceeded(RateLimitExceededEvent $event): void
    {
        // Business decision: How to respond to this violation
        if ($this->isTrustedClient($event->clientIp)) {
            // Increase limits for trusted clients
            $this->rateLimiter->increaseLimit($event->clientIp);
        } else {
            // Apply business-specific penalties
            $this->applySecurityPenalty($event);
        }
    }
}
```

### 4. Performance Considerations

```php
// ✅ Lightweight event processing
class PerformantListener
{
    public function onHeavyEvent(HeavyEvent $event): void
    {
        // Queue heavy processing instead of doing it synchronously
        $this->queue->push(ProcessHeavyEventJob::class, [
            'event_data' => $event->getEventData()
        ]);
    }
}

// ✅ Conditional processing
class ConditionalListener
{
    public function onFrequentEvent(FrequentEvent $event): void
    {
        // Only process if certain conditions are met
        if ($event->requiresProcessing()) {
            $this->doProcessing($event);
        }
    }
}
```

## Command Reference

### Creating Events and Listeners

```bash
# Create a new event class
php glueful event:create OrderShippedEvent

# Create an event listener
php glueful event:listener OrderShippedListener

# Create an event subscriber
php glueful event:subscriber OrderEventSubscriber

# List all registered events and listeners
php glueful event:list

# Debug event listeners for a specific event
php glueful event:debug UserAuthenticatedEvent
```

### Event System Diagnostics

```bash
# Monitor event performance
php glueful event:monitor --duration=60s

# Show event statistics
php glueful event:stats

# Test event dispatching
php glueful event:test UserAuthenticatedEvent

# Validate event listener configuration
php glueful event:validate
```

### Example Output

```bash
$ php glueful event:stats

Event System Statistics
======================
Total Events Dispatched: 1,247
Active Listeners: 23
Average Processing Time: 2.3ms

Top Events by Frequency:
  1. QueryExecutedEvent (45.2%)
  2. RequestReceivedEvent (23.1%)
  3. CacheOperationEvent (12.8%)
  4. SessionActivityEvent (8.9%)
  5. ResponseSentEvent (6.7%)

Slowest Listeners:
  1. AnalyticsListener::onSessionActivity (15.2ms avg)
  2. SecurityMonitor::onRateLimitExceeded (8.7ms avg)
  3. ReportGenerator::onUserAuthenticated (5.1ms avg)
```

This comprehensive event system documentation provides the foundation for building robust, maintainable applications with clear separation between framework infrastructure and application business logic. The event-driven architecture enables powerful integration patterns while maintaining performance and reliability.