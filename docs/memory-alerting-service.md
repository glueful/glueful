# Memory Alerting Service

## Overview

The `MemoryAlertingService` provides advanced memory monitoring with intelligent alerting capabilities. It helps detect and respond to memory-related issues before they cause application failures. This tool is part of Glueful's performance optimization toolkit introduced in v0.27.0.

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [Usage Examples](#usage-examples)
- [Alert Levels](#alert-levels)
- [Alert Channels](#alert-channels)
- [Cooldown Management](#cooldown-management)
- [Rate Limiting](#rate-limiting)
- [Integration with Applications](#integration-with-applications)
- [Configuration](#configuration)
- [Best Practices](#best-practices)

## Key Features

The `MemoryAlertingService` provides several critical capabilities:

- **Proactive memory monitoring**: Detect memory issues before they cause failures
- **Multi-level alerting**: Different alert levels based on severity
- **Alert cooldown**: Prevent alert storms with configurable cooldown periods
- **Multiple notification channels**: Log, email, Slack, and other notification options
- **Alert rate limiting**: Control maximum number of alerts in a time period
- **Contextual information**: Include relevant context with alerts for troubleshooting

## Usage Examples

### Basic Usage

```php
// Create a memory alerting service with default configuration
$memoryManager = new \Glueful\Performance\MemoryManager($logger);
$alertingService = new \Glueful\Performance\MemoryAlertingService($memoryManager, $logger);

// Check memory usage and trigger alerts if thresholds are exceeded
$usage = $alertingService->checkAndAlert('api-request', ['route' => '/users']);

// Output memory usage information
echo "Current memory: {$usage['formatted']['current']}\n";
echo "Memory limit: {$usage['formatted']['limit']}\n";
echo "Usage percentage: " . round($usage['percentage'] * 100, 2) . "%\n";
```

### With Context-Specific Checks

```php
// Create the alerting service
$alertingService = new \Glueful\Performance\MemoryAlertingService($memoryManager, $logger);

// Check during different operations with specific contexts
function processLargeImport($data, $alertingService) {
    // Check before processing
    $alertingService->checkAndAlert('import-start', [
        'items' => count($data),
        'operation' => 'import'
    ]);
    
    // Process data in chunks
    foreach (array_chunk($data, 100) as $index => $chunk) {
        processChunk($chunk);
        
        // Check after each chunk
        $alertingService->checkAndAlert('import-chunk', [
            'chunk' => $index,
            'items' => count($chunk),
            'operation' => 'import'
        ]);
    }
    
    // Final check after processing
    $alertingService->checkAndAlert('import-complete', [
        'items' => count($data),
        'operation' => 'import'
    ]);
}
```

## Alert Levels

The `MemoryAlertingService` supports different alert levels based on memory usage:

```php
// Alert thresholds are configured in performance.php
// Two main thresholds:
// - Warning threshold (default: 85% of memory limit)
// - Critical threshold (default: 95% of memory limit)

$alertingService = new \Glueful\Performance\MemoryAlertingService($memoryManager, $logger);

// Trigger check that will alert based on current memory usage
$usage = $alertingService->checkAndAlert('batch-job');

if ($usage['percentage'] >= 0.95) {
    echo "CRITICAL: Memory usage extremely high!\n";
    // Take emergency action
} elseif ($usage['percentage'] >= 0.85) {
    echo "WARNING: Memory usage high\n";
    // Take preventative action
} else {
    echo "Memory usage normal\n";
}
```

### Warning Alerts

Warning alerts indicate high memory usage that hasn't reached critical levels:

- Triggered when memory usage exceeds the notify threshold (default: 85%)
- Logged with 'warning' log level
- Include memory usage details and context information
- Suggest preventative actions

### Critical Alerts

Critical alerts indicate dangerously high memory usage:

- Triggered when memory usage exceeds the critical threshold (default: 95%)
- Logged with 'error' log level
- Include detailed memory diagnostics and context information
- May trigger emergency memory reclamation actions
- Higher priority notifications

## Alert Channels

The `MemoryAlertingService` can send alerts through multiple channels:

```php
// Channels are configured in performance.php
// Example channels:
// - log: Send to application log
// - email: Send email notifications
// - slack: Post to Slack channel
// - webhook: Send to custom webhook

// The channel selection depends on the alert severity and configuration
```

### Channel-Specific Features

Each channel provides specific capabilities:

**Log Channel**:
- Different log levels based on alert severity
- Structured logging with context details
- Integration with existing logging infrastructure

**Email Channel**:
- Configurable recipients
- HTML-formatted memory diagnostics
- Throttling to prevent email storms

**Slack Channel**:
- Rich formatting with charts and metrics
- Channel selection based on severity
- Mention capabilities for critical alerts

**Webhook Channel**:
- Custom payload format
- Signed requests for security
- Retry logic for delivery assurance

## Cooldown Management

To prevent alert storms, the `MemoryAlertingService` implements cooldown periods:

```php
// Cooldown periods are configured in performance.php
// Default is 300 seconds (5 minutes) between similar alerts

// The alerting service tracks recent alerts by context and type
// If an alert was recently sent for the same context, it will be suppressed
// until the cooldown period expires

// This prevents flooding of notification channels and alert fatigue
```

### Cooldown Algorithm

The cooldown system works as follows:

1. When an alert is triggered, a key is generated based on context and alert type
2. The alerting service checks if this key exists in the recent alerts registry
3. If found and within cooldown period, the alert is suppressed
4. If not found or cooldown expired, the alert is sent and the registry updated

```php
// Example of how cooldown works internally
function isAlertInCooldown(string $alertKey): bool {
    $now = time();
    $cooldownPeriod = $this->config['cooldown'] ?? 300;
    
    if (isset($this->lastAlerts[$alertKey])) {
        $timeSinceLastAlert = $now - $this->lastAlerts[$alertKey];
        return $timeSinceLastAlert < $cooldownPeriod;
    }
    
    return false;
}
```

## Rate Limiting

In addition to cooldown periods, the `MemoryAlertingService` implements rate limiting:

```php
// Rate limiting is configured in performance.php
// Default is 10 alerts per hour maximum

// This prevents excessive alerts even if they are for different contexts
// Once the rate limit is reached, only critical alerts will be sent
```

### Rate Limiting Algorithm

The rate limiting system works as follows:

1. The alerting service tracks the number of alerts sent within the current time window
2. If the number exceeds the configured maximum, non-critical alerts are suppressed
3. Critical alerts are always sent regardless of rate limiting
4. The counter resets after the time window expires

This approach prevents notification fatigue while ensuring critical issues are still reported.

## Integration with Applications

The `MemoryAlertingService` can be integrated at various points in an application:

### HTTP Request Cycle

```php
// In middleware or request handler
$alertingService = new \Glueful\Performance\MemoryAlertingService($memoryManager, $logger);

function handleRequest($request, $next) {
    // Check memory at request start
    $alertingService->checkAndAlert('request-start', [
        'uri' => $request->getUri(),
        'method' => $request->getMethod()
    ]);
    
    // Process request
    $response = $next($request);
    
    // Check memory after request processing
    $alertingService->checkAndAlert('request-end', [
        'uri' => $request->getUri(),
        'method' => $request->getMethod(),
        'status' => $response->getStatusCode()
    ]);
    
    return $response;
}
```

### Background Jobs

```php
// In a job processing system
function processJob($job, $alertingService) {
    // Check memory before job
    $alertingService->checkAndAlert('job-start', [
        'job_id' => $job->getId(),
        'job_type' => $job->getType()
    ]);
    
    try {
        // Execute job
        $result = $job->execute();
        
        // Check memory after job
        $alertingService->checkAndAlert('job-complete', [
            'job_id' => $job->getId(),
            'job_type' => $job->getType(),
            'status' => 'complete'
        ]);
        
        return $result;
    } catch (\Exception $e) {
        // Check memory on job failure
        $alertingService->checkAndAlert('job-error', [
            'job_id' => $job->getId(),
            'job_type' => $job->getType(),
            'error' => $e->getMessage(),
            'status' => 'error'
        ]);
        
        throw $e;
    }
}
```

## Configuration

Memory alerting is configured in the `config/performance.php` file:

```php
// config/performance.php
return [
    'memory' => [
        // ... other memory settings
        
        'alerting' => [
            'enabled' => true,
            'cooldown' => 300, // 5 minutes between similar alerts
            'channels' => ['log', 'slack'],
            'log_level' => 'warning',
            'notify_threshold' => 0.85, // 85% of memory limit
            'critical_threshold' => 0.95, // 95% of memory limit
            'alert_rate_limit' => 10, // Maximum alerts per hour
        ],
    ]
];
```

## Best Practices

For optimal use of the memory alerting service:

1. **Place alert checks at strategic points** in your application lifecycle
2. **Use specific context identifiers** to help troubleshoot memory issues
3. **Configure appropriate thresholds** based on your application characteristics
4. **Balance alerting frequency** to avoid alert fatigue
5. **Include actionable information** in your alert contexts
6. **Monitor alert patterns** to identify systemic issues

Advanced implementation:

```php
// Comprehensive memory monitoring strategy
$memoryManager = new \Glueful\Performance\MemoryManager($logger);
$alertingService = new \Glueful\Performance\MemoryAlertingService($memoryManager, $logger);

class MemoryAwareService {
    private $memoryAlertingService;
    private $operationName;
    
    public function __construct($alertingService, $operationName) {
        $this->memoryAlertingService = $alertingService;
        $this->operationName = $operationName;
    }
    
    public function executeOperation($data) {
        // Initial check
        $this->memoryAlertingService->checkAndAlert(
            "{$this->operationName}-start", 
            ['data_size' => $this->calculateDataSize($data)]
        );
        
        // Process in chunks with periodic checks
        $result = [];
        foreach (array_chunk($data, 100) as $index => $chunk) {
            $chunkResult = $this->processChunk($chunk);
            $result = array_merge($result, $chunkResult);
            
            // Periodic check every 5 chunks
            if ($index % 5 === 0) {
                $this->memoryAlertingService->checkAndAlert(
                    "{$this->operationName}-progress", 
                    [
                        'chunk' => $index, 
                        'processed' => count($result),
                        'total' => count($data)
                    ]
                );
            }
        }
        
        // Final check
        $this->memoryAlertingService->checkAndAlert(
            "{$this->operationName}-complete", 
            [
                'processed' => count($result),
                'total' => count($data),
                'success' => true
            ]
        );
        
        return $result;
    }
    
    private function processChunk($chunk) {
        // Processing logic
    }
    
    private function calculateDataSize($data) {
        // Size calculation logic
    }
}
```

---

*For more information on performance optimization, see the [Memory Manager](./memory-manager.md), [Memory-Efficient Iterators](./memory-efficient-iterators.md), and [Performance Monitoring](./performance-monitoring.md) documentation.*
