<?php

namespace Glueful\Queue\Jobs;

use Glueful\Queue\Job;

/**
 * Send Notification Job
 *
 * Handles asynchronous sending of notifications through various channels.
 * Supports multiple notification types and provides robust retry logic
 * for better delivery reliability.
 *
 * Features:
 * - Multiple notification channels (email, SMS, push, etc.)
 * - Enhanced retry logic for better delivery
 * - Notification status tracking
 * - Fallback mechanisms for failed deliveries
 *
 * Usage:
 * ```php
 * $job = new SendNotification([
 *     'type' => 'email',
 *     'recipient' => 'user@example.com',
 *     'subject' => 'Welcome!',
 *     'message' => 'Welcome to our platform',
 *     'template' => 'welcome_email'
 * ]);
 * Queue::push($job);
 * ```
 *
 * @package Glueful\Queue\Jobs
 */
class SendNotification extends Job
{
    /** @var array Supported notification types */
    private const SUPPORTED_TYPES = [
        'email',
        'sms',
        'push',
        'webhook',
        'slack',
        'discord'
    ];

    /**
     * Send the notification
     *
     * @return void
     * @throws \Exception If notification sending fails
     */
    public function handle(): void
    {
        $data = $this->getData();

        // Validate notification data
        $this->validateNotificationData($data);

        // Get notification service
        $notificationService = $this->getNotificationService();

        // Send notification based on type
        $type = $data['type'];
        $result = $notificationService->send($data);

        // Log successful notification
        $this->logNotificationResult($type, $data, $result, true);
    }

    /**
     * Handle notification failure
     *
     * @param \Exception $exception Exception that caused failure
     * @return void
     */
    public function failed(\Exception $exception): void
    {
        $data = $this->getData();

        error_log(sprintf(
            "Failed to send %s notification to %s: %s",
            $data['type'] ?? 'unknown',
            $data['recipient'] ?? 'unknown',
            $exception->getMessage()
        ));

        // Log failed notification
        $this->logNotificationResult(
            $data['type'] ?? 'unknown',
            $data,
            ['error' => $exception->getMessage()],
            false
        );

        // Try fallback notification if configured
        $this->tryFallbackNotification($data, $exception);
    }

    /**
     * Get maximum retry attempts for notifications
     *
     * Notifications get more retries due to external service dependencies
     *
     * @return int Max attempts
     */
    public function getMaxAttempts(): int
    {
        // Check if specific max attempts is set in job data
        $data = $this->getData();
        if (isset($data['max_attempts'])) {
            return (int) $data['max_attempts'];
        }

        // Default to 5 retries for notifications
        return 5;
    }

    /**
     * Get timeout for notification processing
     *
     * @return int Timeout in seconds
     */
    public function getTimeout(): int
    {
        $data = $this->getData();

        // Allow longer timeout for certain notification types
        $type = $data['type'] ?? 'email';

        return match ($type) {
            'webhook' => 120, // Webhooks might be slower
            'email' => 90,    // Email sending can take time
            'sms' => 45,      // SMS is usually faster
            'push' => 30,     // Push notifications are quick
            default => 60     // Default timeout
        };
    }

    /**
     * Validate notification data
     *
     * @param array $data Notification data
     * @return void
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateNotificationData(array $data): void
    {
        // Check required fields
        if (!isset($data['type'])) {
            throw new \InvalidArgumentException('Notification type is required');
        }

        if (!isset($data['recipient'])) {
            throw new \InvalidArgumentException('Notification recipient is required');
        }

        // Validate notification type
        if (!in_array($data['type'], self::SUPPORTED_TYPES)) {
            throw new \InvalidArgumentException(
                "Unsupported notification type '{$data['type']}'. Supported types: " .
                implode(', ', self::SUPPORTED_TYPES)
            );
        }

        // Type-specific validation
        switch ($data['type']) {
            case 'email':
                if (!filter_var($data['recipient'], FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException('Invalid email address');
                }
                if (empty($data['subject']) || empty($data['message'])) {
                    throw new \InvalidArgumentException('Email subject and message are required');
                }
                break;

            case 'sms':
                if (!$this->isValidPhoneNumber($data['recipient'])) {
                    throw new \InvalidArgumentException('Invalid phone number');
                }
                if (empty($data['message'])) {
                    throw new \InvalidArgumentException('SMS message is required');
                }
                break;

            case 'webhook':
                if (!filter_var($data['recipient'], FILTER_VALIDATE_URL)) {
                    throw new \InvalidArgumentException('Invalid webhook URL');
                }
                break;
        }
    }

    /**
     * Get notification service instance
     *
     * @return object Notification service
     * @throws \Exception If service not available
     */
    private function getNotificationService(): object
    {
        // Try to use existing notification service
        if (class_exists('\\Glueful\\Services\\NotificationService')) {
            return new \Glueful\Services\NotificationService();
        }

        // Fallback to simple notification service
        return new class {
            public function send(array $data): array
            {
                // Simple implementation - could be enhanced
                switch ($data['type']) {
                    case 'email':
                        return $this->sendEmail($data);
                    case 'sms':
                        return $this->sendSms($data);
                    default:
                        throw new \Exception("Notification type '{$data['type']}' not implemented");
                }
            }

            private function sendEmail(array $data): array
            {
                // Simple mail() implementation
                $headers = "From: " . ($data['from'] ?? 'noreply@glueful.com') . "\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

                $success = mail(
                    $data['recipient'],
                    $data['subject'],
                    $data['message'],
                    $headers
                );

                return [
                    'success' => $success,
                    'method' => 'mail',
                    'timestamp' => time()
                ];
            }

            private function sendSms(array $data): array
            {
                // Placeholder SMS implementation
                // In production, integrate with SMS service like Twilio
                error_log("SMS to {$data['recipient']}: {$data['message']}");

                return [
                    'success' => true,
                    'method' => 'log',
                    'timestamp' => time()
                ];
            }
        };
    }

    /**
     * Log notification result
     *
     * @param string $type Notification type
     * @param array $data Notification data
     * @param array $result Send result
     * @param bool $success Whether notification was successful
     * @return void
     */
    private function logNotificationResult(string $type, array $data, array $result, bool $success): void
    {
        $logData = [
            'job_uuid' => $this->getUuid(),
            'type' => $type,
            'recipient' => $data['recipient'],
            'success' => $success,
            'attempts' => $this->getAttempts(),
            'result' => $result,
            'timestamp' => time()
        ];

        // Log to appropriate destination
        if ($success) {
            error_log("Notification sent successfully: " . json_encode($logData));
        } else {
            error_log("Notification failed: " . json_encode($logData));
        }
    }

    /**
     * Try fallback notification method
     *
     * @param array $data Original notification data
     * @param \Exception $exception Original exception
     * @return void
     */
    private function tryFallbackNotification(array $data, \Exception $exception): void
    {
        // Only try fallback if configured
        if (!isset($data['fallback'])) {
            return;
        }

        try {
            $fallbackData = $data['fallback'];
            $fallbackData['original_error'] = $exception->getMessage();

            // Create new notification job for fallback
            $fallbackJob = new self($fallbackData);

            // Note: In a real implementation, you'd queue this through the queue manager
            error_log("Fallback notification queued: " . json_encode($fallbackData));

        } catch (\Exception $e) {
            error_log("Fallback notification also failed: " . $e->getMessage());
        }
    }

    /**
     * Validate phone number format
     *
     * @param string $phone Phone number
     * @return bool True if valid
     */
    private function isValidPhoneNumber(string $phone): bool
    {
        // Simple phone validation - could be enhanced
        return preg_match('/^\+?[1-9]\d{1,14}$/', $phone);
    }

    /**
     * Create notification job with validation
     *
     * @param string $type Notification type
     * @param string $recipient Recipient (email, phone, URL, etc.)
     * @param string $message Message content
     * @param array $options Additional options
     * @return self SendNotification job
     * @throws \InvalidArgumentException If invalid parameters
     */
    public static function create(
        string $type,
        string $recipient,
        string $message,
        array $options = []
    ): self {
        $data = array_merge($options, [
            'type' => $type,
            'recipient' => $recipient,
            'message' => $message
        ]);

        $job = new self($data);

        // Validate the created job
        $job->validateNotificationData($data);

        return $job;
    }

    /**
     * Create email notification job
     *
     * @param string $recipient Email address
     * @param string $subject Email subject
     * @param string $message Email content
     * @param array $options Additional options
     * @return self SendNotification job
     */
    public static function email(
        string $recipient,
        string $subject,
        string $message,
        array $options = []
    ): self {
        return self::create('email', $recipient, $message, array_merge($options, [
            'subject' => $subject
        ]));
    }

    /**
     * Create SMS notification job
     *
     * @param string $recipient Phone number
     * @param string $message SMS content
     * @param array $options Additional options
     * @return self SendNotification job
     */
    public static function sms(
        string $recipient,
        string $message,
        array $options = []
    ): self {
        return self::create('sms', $recipient, $message, $options);
    }

    /**
     * Get job description for logging
     *
     * @return string Job description
     */
    public function getDescription(): string
    {
        $data = $this->getData();
        return sprintf(
            'SendNotification: %s to %s (UUID: %s)',
            $data['type'] ?? 'unknown',
            $data['recipient'] ?? 'unknown',
            $this->getUuid()
        );
    }

    /**
     * Get notification summary for monitoring
     *
     * @return array Notification summary
     */
    public function getNotificationSummary(): array
    {
        $data = $this->getData();

        return [
            'type' => $data['type'] ?? null,
            'recipient' => $data['recipient'] ?? null,
            'has_subject' => isset($data['subject']),
            'message_length' => strlen($data['message'] ?? ''),
            'has_template' => isset($data['template']),
            'has_fallback' => isset($data['fallback'])
        ];
    }
}