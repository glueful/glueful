<?php

namespace Glueful\Controllers\Traits;

use Glueful\Queue\QueueManager;
use Glueful\Queue\Jobs\ProcessAuditLog;
use Glueful\Logging\AuditEvent;

/**
 * Async Audit Trait
 *
 * Provides asynchronous audit logging capabilities for controllers.
 * Determines whether to use sync or async logging based on severity
 * and configuration settings.
 *
 * Features:
 * - Intelligent sync/async decision making
 * - Severity-based routing
 * - Configuration-driven behavior
 * - Fallback to synchronous logging
 * - Queue-based async processing
 *
 * @package Glueful\Controllers\Traits
 */
trait AsyncAuditTrait
{
    /** @var QueueManager|null Queue manager instance */
    private ?QueueManager $queueManager = null;

    /**
     * Perform asynchronous audit logging
     *
     * Intelligently routes audit events to either synchronous or
     * asynchronous processing based on severity and configuration.
     * Now delegates to AuditLogger's implementation for consistency.
     *
     * Critical events (ERROR, CRITICAL) are always processed synchronously
     * to ensure immediate logging. Non-critical events can be queued for
     * async processing to improve performance.
     *
     * @param string $category Audit category
     * @param string $action Action performed
     * @param string $severity Event severity
     * @param array $context Additional context data
     * @return string Event ID
     */
    protected function asyncAudit(string $category, string $action, string $severity, array $context = []): string
    {
        // Use AuditLogger's audit method which now has built-in async detection
        if (isset($this->auditLogger)) {
            return $this->auditLogger->audit($category, $action, $severity, $context);
        }

        // Fallback for controllers without auditLogger
        $useAsync = $this->shouldUseAsyncLogging($category, $severity);

        if ($useAsync) {
            $this->queueAuditLog($category, $action, $severity, $context);
            return 'queued-' . uniqid();
        } else {
            // Synchronous logging for critical events
            $this->performSyncAudit($category, $action, $severity, $context);
            return 'sync-' . uniqid();
        }
    }

    /**
     * Log high-volume category events (automatically uses async processing)
     *
     * @param string $category High-volume category
     * @param string $action Action performed
     * @param array $context Additional context data
     * @param string $severity Event severity
     * @return string Event ID
     */
    protected function logHighVolumeEvent(
        string $category,
        string $action,
        array $context = [],
        string $severity = AuditEvent::SEVERITY_INFO
    ): string {
        return $this->asyncAudit($category, $action, $severity, $context);
    }


    /**
     * Log API access event (high-volume)
     *
     * @param string $endpoint API endpoint
     * @param string $method HTTP method
     * @param array $context Additional context
     * @return string Event ID
     */
    protected function logApiAccess(
        string $endpoint,
        string $method,
        array $context = []
    ): string {
        $enrichedContext = array_merge($context, [
            'endpoint' => $endpoint,
            'method' => $method
        ]);

        return $this->logHighVolumeEvent('api_access', 'access', $enrichedContext);
    }

    /**
     * Queue audit log for asynchronous processing
     *
     * @param string $category Audit category
     * @param string $action Action performed
     * @param string $severity Event severity
     * @param array $context Additional context data
     * @return void
     */
    private function queueAuditLog(string $category, string $action, string $severity, array $context): void
    {
        try {
            $queueManager = $this->getQueueManager();
            // Add request context if available
            $enrichedContext = $this->enrichAuditContext($context);

            $queueManager->push(ProcessAuditLog::class, [
                'category' => $category,
                'action' => $action,
                'severity' => $severity,
                'context' => $enrichedContext,
                'user_id' => $this->getCurrentUserId(),
                'session_id' => $this->getCurrentSessionId(),
                'ip_address' => $this->getClientIpAddress(),
                'user_agent' => $this->getUserAgent(),
                'timestamp' => time(),
                'request_id' => $this->getRequestId(),
            ], $this->getAuditQueue());
        } catch (\Exception $e) {
            // Fallback to synchronous logging if queue fails
            error_log("Failed to queue audit log: " . $e->getMessage());
            $this->performSyncAudit($category, $action, $severity, $context);
        }
    }

    /**
     * Perform synchronous audit logging
     *
     * @param string $category Audit category
     * @param string $action Action performed
     * @param string $severity Event severity
     * @param array $context Additional context data
     * @return void
     */
    private function performSyncAudit(string $category, string $action, string $severity, array $context): void
    {
        try {
            // Use existing audit logger if available
            if (isset($this->auditLogger)) {
                $this->auditLogger->audit($category, $action, $severity, $context);
            } else {
                // Fallback logging
                $this->logAuditEvent($category, $action, $severity, $context);
            }
        } catch (\Exception $e) {
            // Last resort - error log
            error_log("Audit logging failed: " . $e->getMessage());
        }
    }

    /**
     * Determine if async logging should be used
     *
     * @param string $category Event category
     * @param string $severity Event severity
     * @return bool True if async logging should be used
     */
    private function shouldUseAsyncLogging(string $category, string $severity = AuditEvent::SEVERITY_INFO): bool
    {
        // High-volume categories that should be processed asynchronously
        $highVolumeCategories = [
            AuditEvent::CATEGORY_DATA,
            AuditEvent::CATEGORY_FILE,
            AuditEvent::CATEGORY_SYSTEM,
            'resource_access',
            'api_access'
        ];

        // Critical categories that should always be processed synchronously
        $criticalCategories = [
            AuditEvent::CATEGORY_AUTH,
            AuditEvent::CATEGORY_AUTHZ,
            AuditEvent::CATEGORY_ADMIN,
            AuditEvent::CATEGORY_CONFIG
        ];

        // Always use sync for critical categories
        if (in_array($category, $criticalCategories)) {
            return false;
        }

        // Always use sync for critical severities
        $criticalSeverities = [
            AuditEvent::SEVERITY_ERROR,
            AuditEvent::SEVERITY_CRITICAL,
            AuditEvent::SEVERITY_ALERT,
            AuditEvent::SEVERITY_EMERGENCY
        ];

        if (in_array($severity, $criticalSeverities)) {
            return false;
        }

        // Use async for high-volume categories
        if (in_array($category, $highVolumeCategories)) {
            // Check if async logging is enabled
            $asyncEnabled = $this->getConfig('audit.async_enabled', true);
            if (!$asyncEnabled) {
                return false;
            }

            // Check if queue is available and healthy
            return $this->isQueueHealthy();
        }

        // Default to sync for other categories
        return false;
    }

    /**
     * Enrich audit context with request information
     *
     * @param array $context Original context
     * @return array Enriched context
     */
    private function enrichAuditContext(array $context): array
    {
        $enriched = $context;

        // Add request information if available
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $enriched['request_method'] = $_SERVER['REQUEST_METHOD'];
        }

        if (isset($_SERVER['REQUEST_URI'])) {
            $enriched['request_uri'] = $_SERVER['REQUEST_URI'];
        }

        if (isset($_SERVER['HTTP_REFERER'])) {
            $enriched['referer'] = $_SERVER['HTTP_REFERER'];
        }

        // Add controller and action context
        $enriched['controller'] = static::class;
        $enriched['action'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2]['function'] ?? 'unknown';

        return $enriched;
    }

    /**
     * Get queue manager instance
     *
     * @return QueueManager Queue manager
     */
    private function getQueueManager(): QueueManager
    {
        if ($this->queueManager === null) {
            // Try to get from container if available
            if (function_exists('container')) {
                $this->queueManager = container()->get(QueueManager::class);
            } else {
                // Create new instance
                $this->queueManager = new QueueManager();
            }
        }

        return $this->queueManager;
    }

    /**
     * Get audit queue name
     *
     * @return string Queue name for audit logs
     */
    private function getAuditQueue(): string
    {
        return $this->getConfig('audit.queue_name', 'audit_logs');
    }

    /**
     * Check if queue is healthy
     *
     * @return bool True if queue is healthy
     */
    private function isQueueHealthy(): bool
    {
        try {
            $queueManager = $this->getQueueManager();
            $health = $queueManager->testConnection();
            return $health['healthy'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get current user ID
     *
     * @return int|null User ID
     */
    private function getCurrentUserId(): ?int
    {
        // Try different methods to get user ID
        if (isset($this->user) && method_exists($this->user, 'getId')) {
            return $this->user->getId();
        }

        if (isset($_SESSION['user_id'])) {
            return (int) $_SESSION['user_id'];
        }

        return null;
    }

    /**
     * Get current session ID
     *
     * @return string|null Session ID
     */
    private function getCurrentSessionId(): ?string
    {
        return session_id() ?: null;
    }

    /**
     * Get client IP address
     *
     * @return string Client IP address
     */
    private function getClientIpAddress(): string
    {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Handle comma-separated IPs (proxy chains)
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Get user agent
     *
     * @return string User agent
     */
    private function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }

    /**
     * Get request ID
     *
     * @return string Request ID
     */
    private function getRequestId(): string
    {
        // Try to get from headers first
        if (!empty($_SERVER['HTTP_X_REQUEST_ID'])) {
            return $_SERVER['HTTP_X_REQUEST_ID'];
        }

        // Generate if not available
        return uniqid('req_', true);
    }

    /**
     * Get configuration value
     *
     * @param string $key Configuration key
     * @param mixed $default Default value
     * @return mixed Configuration value
     */
    private function getConfig(string $key, $default = null)
    {
        // Try different methods to get config
        if (function_exists('config')) {
            return config($key, $default);
        }

        // Fallback to $_ENV
        $envKey = strtoupper(str_replace('.', '_', $key));
        return $_ENV[$envKey] ?? $default;
    }

    /**
     * Fallback audit logging method
     *
     * @param string $category Audit category
     * @param string $action Action performed
     * @param string $severity Event severity
     * @param array $context Additional context data
     * @return void
     */
    private function logAuditEvent(string $category, string $action, string $severity, array $context): void
    {
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'category' => $category,
            'action' => $action,
            'severity' => $severity,
            'context' => $context,
            'user_id' => $this->getCurrentUserId(),
            'ip_address' => $this->getClientIpAddress(),
        ];

        error_log("AUDIT: " . json_encode($logData));
    }
}
