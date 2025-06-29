<?php

namespace Glueful\Performance;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Glueful\Http\Client;
use Glueful\Exceptions\HttpException;

/**
 * Service for monitoring memory usage and triggering alerts
 */
class MemoryAlertingService
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var MemoryManager
     */
    private $memoryManager;

    /**
     * @var array
     */
    private $config;

    /**
     * @var array
     */
    private $lastAlerts = [];

    /**
     * @var int
     */
    private $alertCount = 0;

    /**
     * Initialize the Memory Alerting Service
     *
     * @param MemoryManager $memoryManager
     * @param LoggerInterface|null $logger
     */
    public function __construct(MemoryManager $memoryManager, ?LoggerInterface $logger = null)
    {
        $this->memoryManager = $memoryManager;
        $this->logger = $logger ?? new NullLogger();
        $this->config = config('app.performance.memory.alerting', [
            'enabled' => true,
            'cooldown' => 300, // 5 minutes between similar alerts
            'channels' => ['log', 'slack'],
            'log_level' => 'warning',
            'notify_threshold' => 0.85, // 85% of memory limit
            'critical_threshold' => 0.95, // 95% of memory limit
            'alert_rate_limit' => 10, // Maximum alerts per hour
        ]);
    }

    /**
     * Check memory usage and trigger alerts if thresholds are exceeded
     *
     * @param string $context Context identifier for the current operation
     * @param array $tags Tags for categorizing the alert
     * @return array Memory usage information
     */
    public function checkAndAlert(string $context = 'application', array $tags = []): array
    {
        if (!$this->config['enabled']) {
            return $this->memoryManager->getCurrentUsage();
        }

        $usage = $this->memoryManager->getCurrentUsage();
        $notifyThreshold = $this->config['notify_threshold'] ?? 0.85;
        $criticalThreshold = $this->config['critical_threshold'] ?? 0.95;

        if ($usage['percentage'] >= $criticalThreshold) {
            $this->triggerCriticalAlert($usage, $context, $tags);
        } elseif ($usage['percentage'] >= $notifyThreshold) {
            $this->triggerWarningAlert($usage, $context, $tags);
        }

        return $usage;
    }

    /**
     * Trigger a warning alert for high memory usage
     *
     * @param array $usage Memory usage information
     * @param string $context Context identifier
     * @param array $tags Alert tags
     * @return void
     */
    private function triggerWarningAlert(array $usage, string $context, array $tags): void
    {
        $alertKey = "{$context}:warning";

        // Check cooldown period
        if ($this->isAlertInCooldown($alertKey)) {
            return;
        }

        // Check rate limit
        if ($this->isRateLimitExceeded()) {
            $this->logger->info('Memory warning alert rate limit exceeded');
            return;
        }

        $message = sprintf(
            'High memory usage detected in %s: %.2f%% of limit (%s/%s)',
            $context,
            $usage['percentage'] * 100,
            $this->formatBytes($usage['current']),
            $this->formatBytes($usage['limit'])
        );

        $this->recordAlert($alertKey);
        $this->sendAlert('warning', $message, $usage, $context, $tags);
    }

    /**
     * Trigger a critical alert for dangerously high memory usage
     *
     * @param array $usage Memory usage information
     * @param string $context Context identifier
     * @param array $tags Alert tags
     * @return void
     */
    private function triggerCriticalAlert(array $usage, string $context, array $tags): void
    {
        $alertKey = "{$context}:critical";

        // Critical alerts have a shorter cooldown
        if ($this->isAlertInCooldown($alertKey, 60)) {
            return;
        }

        // Critical alerts bypass normal rate limiting

        $message = sprintf(
            'CRITICAL memory usage detected in %s: %.2f%% of limit (%s/%s)',
            $context,
            $usage['percentage'] * 100,
            $this->formatBytes($usage['current']),
            $this->formatBytes($usage['limit'])
        );

        // Attempt to free memory
        $gcResult = $this->memoryManager->forceGarbageCollection();
        $message .= $gcResult ? ' (Garbage collection triggered)' : ' (Garbage collection failed)';

        $this->recordAlert($alertKey);
        $this->sendAlert('critical', $message, $usage, $context, $tags);
    }

    /**
     * Send an alert through configured channels
     *
     * @param string $level Alert level (warning, critical)
     * @param string $message Alert message
     * @param array $usage Memory usage data
     * @param string $context Alert context
     * @param array $tags Alert tags
     * @return void
     */
    private function sendAlert(string $level, string $message, array $usage, string $context, array $tags): void
    {
        $channels = $this->config['channels'] ?? ['log'];
        $alertData = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'tags' => $tags,
            'usage' => $usage,
            'timestamp' => date('Y-m-d H:i:s'),
            'hostname' => gethostname(),
            'process_id' => getmypid(),
        ];

        foreach ($channels as $channel) {
            switch ($channel) {
                case 'log':
                    $this->sendLogAlert($level, $message, $alertData);
                    break;

                case 'slack':
                    $this->sendSlackAlert($level, $message, $alertData);
                    break;

                case 'email':
                    $this->sendEmailAlert($level, $message, $alertData);
                    break;

                case 'webhook':
                    $this->sendWebhookAlert($level, $message, $alertData);
                    break;

                default:
                    $this->logger->warning("Unknown alert channel: {$channel}");
            }
        }
    }

    /**
     * Send an alert to the log
     *
     * @param string $level Alert level
     * @param string $message Alert message
     * @param array $data Additional alert data
     * @return void
     */
    private function sendLogAlert(string $level, string $message, array $data): void
    {
        $logLevel = $level === 'critical' ? 'error' : $this->config['log_level'] ?? 'warning';
        $this->logger->log($logLevel, $message, [
            'memory_usage' => $data['usage'],
            'context' => $data['context'],
            'tags' => $data['tags']
        ]);
    }

    /**
     * Send an alert to Slack
     *
     * @param string $level Alert level
     * @param string $message Alert message
     * @param array $data Additional alert data
     * @return void
     */
    private function sendSlackAlert(string $level, string $message, array $data): void
    {
        // Implementation depends on how Slack notifications are configured in the system
        $slackConfig = config('notifications.slack', null);

        if (!$slackConfig || empty($slackConfig['webhook_url'])) {
            $this->logger->warning('Slack webhook URL not configured, could not send memory alert');
            return;
        }

        // Simple implementation - in a real system, this would use the application's notification system
        $color = $level === 'critical' ? 'danger' : 'warning';

        $payload = json_encode([
            'text' => $message,
            'attachments' => [
                [
                    'color' => $color,
                    'title' => 'Memory Usage Alert',
                    'fields' => [
                        [
                            'title' => 'Context',
                            'value' => $data['context'],
                            'short' => true
                        ],
                        [
                            'title' => 'Current Usage',
                            'value' => $this->formatBytes($data['usage']['current']),
                            'short' => true
                        ],
                        [
                            'title' => 'Memory Limit',
                            'value' => $this->formatBytes($data['usage']['limit']),
                            'short' => true
                        ],
                        [
                            'title' => 'Usage Percentage',
                            'value' => sprintf('%.2f%%', $data['usage']['percentage'] * 100),
                            'short' => true
                        ],
                    ],
                    'footer' => "Server: {$data['hostname']} | Process: {$data['process_id']} | " .
                        "Time: {$data['timestamp']}"
                ]
            ]
        ]);

        // Send the notification - in a production system, this would be queued
        try {
            $client = new Client([
                'timeout' => 10,
                'connect_timeout' => 5
            ]);

            $response = $client->post($slackConfig['webhook_url'], [
                'json' => json_decode($payload, true)
            ]);

            if (!$response->isSuccessful()) {
                $this->logger->warning('Failed to send Slack alert', [
                    'http_code' => $response->getStatusCode(),
                    'response' => $response->getBody()
                ]);
            }
        } catch (HttpException $e) {
            $this->logger->error('Failed to send Slack alert', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Send an alert via email
     *
     * @param string $level Alert level
     * @param string $message Alert message
     * @param array $data Additional alert data
     * @return void
     */
    private function sendEmailAlert(string $level, string $message, array $data): void
    {
        // Implementation depends on how email notifications are configured in the system
        $emailConfig = config('notifications.email', null);

        if (!$emailConfig || empty($emailConfig['to'])) {
            $this->logger->warning('Email configuration missing, could not send memory alert');
            return;
        }

        // In a real system, this would use the application's email service
        $this->logger->info('Would send email alert: ' . $message);
    }

    /**
     * Send an alert to a webhook
     *
     * @param string $level Alert level
     * @param string $message Alert message
     * @param array $data Additional alert data
     * @return void
     */
    private function sendWebhookAlert(string $level, string $message, array $data): void
    {
        $webhookConfig = config('notifications.webhook', null);

        if (!$webhookConfig || empty($webhookConfig['url'])) {
            $this->logger->warning('Webhook URL not configured, could not send memory alert');
            return;
        }

        // Prepare data for webhook
        $payload = json_encode([
            'level' => $level,
            'message' => $message,
            'data' => $data
        ]);

        // Send the notification
        try {
            $client = new Client([
                'timeout' => 10,
                'connect_timeout' => 5,
                'headers' => [
                    'X-Alert-Type' => 'memory'
                ]
            ]);

            $response = $client->post($webhookConfig['url'], [
                'json' => json_decode($payload, true)
            ]);

            if (!$response->isSuccessful()) {
                $this->logger->warning('Failed to send webhook alert', [
                    'http_code' => $response->getStatusCode(),
                    'response' => $response->getBody()
                ]);
            }
        } catch (HttpException $e) {
            $this->logger->error('Failed to send webhook alert', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Record that an alert was sent
     *
     * @param string $key Alert key
     * @return void
     */
    private function recordAlert(string $key): void
    {
        $this->lastAlerts[$key] = time();
        $this->alertCount++;
    }

    /**
     * Check if a similar alert was sent recently
     *
     * @param string $key Alert key
     * @param int|null $customCooldown Custom cooldown period in seconds
     * @return bool
     */
    private function isAlertInCooldown(string $key, ?int $customCooldown = null): bool
    {
        $cooldown = $customCooldown ?? $this->config['cooldown'] ?? 300;

        if (!isset($this->lastAlerts[$key])) {
            return false;
        }

        return (time() - $this->lastAlerts[$key]) < $cooldown;
    }

    /**
     * Check if we've exceeded the alert rate limit
     *
     * @return bool
     */
    private function isRateLimitExceeded(): bool
    {
        $rateLimit = $this->config['alert_rate_limit'] ?? 10;
        return $this->alertCount >= $rateLimit;
    }

    /**
     * Reset the alert rate limiter
     *
     * @return void
     */
    public function resetRateLimit(): void
    {
        $this->alertCount = 0;
    }

    /**
     * Format bytes into a human-readable string
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
