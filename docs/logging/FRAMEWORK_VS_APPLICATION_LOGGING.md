# Glueful Framework Logging Guide

## Framework vs Application Logging

Glueful follows industry best practices for framework logging boundaries, ensuring clear separation of concerns between framework infrastructure and application business logic.

### ✅ **Framework Automatically Logs** (Infrastructure Concerns)

The framework handles these logging concerns automatically:

- **Unhandled Exceptions & Fatal Errors** - Framework-level failures and PHP errors
- **HTTP Protocol Errors** - Malformed requests, routing failures, invalid JSON
- **Framework Lifecycle Events** - Startup, shutdown, configuration loading
- **HTTP Auth Failures** - Missing headers, malformed JWT tokens (protocol level)
- **Slow Query Detection** - Configurable performance monitoring
- **HTTP Client Infrastructure Failures** - Connection timeouts, DNS issues, server errors
- **API Deprecation Warnings** - Framework-managed endpoint versioning

### 🔧 **Application Should Log** (Business Concerns)

Your application code should handle these logging scenarios via events and custom logging:

- **Business Authentication Logic** - User login attempts, permission checks
- **Business External Service Failures** - Payment processing, email delivery status
- **User Behavior Tracking** - Custom analytics and business metrics
- **Custom Validation** - Business rule violations and domain-specific errors
- **CRUD Operations** - User data changes, audit trails
- **Business State Changes** - Order status updates, user role changes

## Configuration

### Framework Logging Settings

Framework logging is configured in `config/logging.php`:

```php
'framework' => [
    'enabled' => env('FRAMEWORK_LOGGING_ENABLED', true),
    'level' => env('FRAMEWORK_LOG_LEVEL', 'info'),
    'channel' => env('FRAMEWORK_LOG_CHANNEL', 'framework'),
    
    // Feature-specific toggles
    'log_exceptions' => env('LOG_FRAMEWORK_EXCEPTIONS', true),
    'log_deprecations' => env('LOG_FRAMEWORK_DEPRECATIONS', true),
    'log_lifecycle' => env('LOG_FRAMEWORK_LIFECYCLE', true),
    'log_protocol_errors' => env('LOG_FRAMEWORK_PROTOCOL_ERRORS', true),
    
    // Performance monitoring (optional)
    'slow_requests' => [
        'enabled' => env('LOG_SLOW_REQUESTS', true),
        'threshold_ms' => env('SLOW_REQUEST_THRESHOLD', 1000),
    ],
    'slow_queries' => [
        'enabled' => env('LOG_SLOW_QUERIES', true),
        'threshold_ms' => env('SLOW_QUERY_THRESHOLD', 200),
    ],
    'http_client' => [
        'log_failures' => env('LOG_HTTP_CLIENT_FAILURES', true),
        'slow_threshold_ms' => env('HTTP_CLIENT_SLOW_THRESHOLD', 5000)
    ]
]
```

### Environment Variables

Add these to your `.env` file:

```bash
# Framework Logging (Infrastructure/Protocol concerns)
FRAMEWORK_LOGGING_ENABLED=true
FRAMEWORK_LOG_LEVEL=info
LOG_FRAMEWORK_EXCEPTIONS=true
LOG_FRAMEWORK_DEPRECATIONS=true
LOG_FRAMEWORK_LIFECYCLE=true
LOG_FRAMEWORK_PROTOCOL_ERRORS=true

# Framework Performance Monitoring
LOG_SLOW_REQUESTS=true
SLOW_REQUEST_THRESHOLD=1000
LOG_SLOW_QUERIES=true
SLOW_QUERY_THRESHOLD=200
LOG_HTTP_CLIENT_FAILURES=true
HTTP_CLIENT_SLOW_THRESHOLD=5000
```

## Using Framework Events for Application Logging

The framework emits events that your application can listen to for business logging:

### 1. Security Events

```php
use Glueful\Events\RateLimitExceededEvent;
use Glueful\Events\HttpAuthFailureEvent;

class SecurityLoggingListener
{
    private LoggerInterface $logger;
    
    public function onRateLimitExceeded(RateLimitExceededEvent $event): void
    {
        // Your business security logging
        $this->logger->warning('Rate limit violation detected', [
            'ip_address' => $event->ipAddress,
            'endpoint' => $event->endpoint,
            'method' => $event->method,
            'user_agent' => $event->request->headers->get('User-Agent'),
            'timestamp' => now()->toISOString()
        ]);
        
        // Custom business logic
        if ($this->isSuspiciousActivity($event)) {
            $this->blacklistIp($event->ipAddress);
            $this->sendSecurityAlert($event);
        }
    }
    
    public function onHttpAuthFailure(HttpAuthFailureEvent $event): void
    {
        // Application logs business context of auth failures
        $this->logger->info('Authentication attempt failed', [
            'reason' => $event->reason,
            'ip_address' => $event->request->getClientIp(),
            'endpoint' => $event->request->getPathInfo(),
            'user_agent' => $event->request->headers->get('User-Agent'),
            'timestamp' => now()->toISOString()
        ]);
    }
}
```

### 2. Query Events

```php
use Glueful\Events\QueryExecutedEvent;

class QueryLoggingListener
{
    public function onQueryExecuted(QueryExecutedEvent $event): void
    {
        // Log business-specific query context
        if ($this->isBusinessCriticalTable($event->sql)) {
            $this->logger->info('Business critical query executed', [
                'table' => $this->extractTableName($event->sql),
                'operation' => $this->detectOperation($event->sql),
                'execution_time_ms' => $event->executionTime * 1000,
                'user_id' => $this->getCurrentUserId()
            ]);
        }
    }
}
```

## Request Context Logging

Use the framework's contextual logging for consistent request correlation:

```php
// In your controllers
public function createUser(Request $request): Response
{
    // Get contextual logger with request context pre-populated
    $logger = $request->attributes->get('contextual_logger')();
    
    $logger->info('Creating new user', [
        'type' => 'application',
        'email' => $request->request->get('email'),
        'registration_source' => 'api'
    ]);
    // Log automatically includes: request_id, ip, user_agent, path, method, user_id
    
    // Your business logic...
    
    $logger->info('User created successfully', [
        'type' => 'application',
        'user_uuid' => $user->uuid,
        'email' => $user->email
    ]);
    
    return Response::created($user, 'User created successfully');
}
```

## Deprecation Management

Configure deprecated API endpoints in `config/api.php`:

```php
'deprecated_routes' => [
    '/api/v1/users' => [
        'since' => '2.0.0',
        'removal_version' => '3.0.0',
        'replacement' => '/api/v2/users',
        'reason' => 'Improved user data structure'
    ],
    'GET /api/legacy/*' => [
        'since' => '1.5.0',
        'removal_version' => '2.0.0',
        'replacement' => '/api/v2/*'
    ]
]
```

The framework will automatically:
- Log deprecation warnings to the framework channel
- Add deprecation headers to responses
- Provide client guidance for migration

## Production Recommendations

### Framework Logging (Production)
```bash
FRAMEWORK_LOGGING_ENABLED=true
FRAMEWORK_LOG_LEVEL=error  # Only log errors in production
LOG_FRAMEWORK_EXCEPTIONS=true
LOG_FRAMEWORK_DEPRECATIONS=true
LOG_FRAMEWORK_LIFECYCLE=false  # Reduce noise
LOG_FRAMEWORK_PROTOCOL_ERRORS=true

# Performance monitoring (adjust thresholds for production)
SLOW_REQUEST_THRESHOLD=2000  # 2 seconds
SLOW_QUERY_THRESHOLD=500     # 500ms
```

### Application Logging (Production)
```bash
LOG_LEVEL=error  # Application should log errors/warnings
LOG_TO_FILE=true
LOG_TO_DB=false  # Consider impact on performance
LOG_ROTATION_DAYS=30
```

## Log Channels

Glueful uses separate log channels for different concerns:

- **framework** - Framework infrastructure logging
- **app** - Application business logic
- **api** - API request/response logging
- **error** - Error-specific logging
- **debug** - Development debugging

## Best Practices

### ✅ Do
- Use events for business logging
- Leverage contextual logging for request correlation
- Log user actions and business state changes
- Configure appropriate log levels for each environment
- Use structured logging with consistent field names

### ❌ Don't
- Log business logic in framework middleware
- Duplicate framework logging in application code
- Log sensitive information (passwords, tokens, PII)
- Create custom logging for framework concerns
- Mix business and infrastructure logging

## Monitoring and Alerting

### Framework Logs to Monitor
- High frequency of HTTP protocol errors (potential attacks)
- Unhandled exceptions (framework stability)
- Slow query/request patterns (performance issues)
- Deprecation usage (migration planning)

### Application Logs to Monitor
- Authentication failure patterns (security)
- Business critical operation failures (reliability)
- User behavior anomalies (fraud detection)
- External service failures (integration health)

## Troubleshooting

### Common Issues

1. **No framework logs appearing**
   - Check `FRAMEWORK_LOGGING_ENABLED=true`
   - Verify log file permissions
   - Check framework log level configuration

2. **Too many framework logs**
   - Increase `FRAMEWORK_LOG_LEVEL` (debug → info → warning → error)
   - Disable specific features (lifecycle, deprecations)
   - Adjust performance thresholds

3. **Missing application context**
   - Ensure event listeners are registered
   - Use contextual logger in controllers
   - Verify user context is available after authentication

### Log File Locations
- Framework: `storage/logs/framework.log`
- Application: `storage/logs/app.log`
- API: `storage/logs/api.log`
- Errors: `storage/logs/error.log`

## Summary

Glueful's logging architecture provides:

✅ **Automatic framework logging** for infrastructure concerns
🔧 **Event-driven application logging** for business concerns  
📊 **Request context correlation** for debugging
⚡ **Configurable performance monitoring**
🔒 **Security-focused event emission**
📈 **Production-ready log management**

This approach ensures you get comprehensive infrastructure monitoring automatically while maintaining full control over your application-specific logging requirements.