<?php

declare(strict_types=1);

namespace Glueful\Notifications\Utils;

/**
 * Notification Result Parser
 *
 * Utility class for parsing and standardizing notification service results.
 * Extracts useful information from notification response and converts it to
 * a consistent format that can be used throughout the application.
 *
 * @package Glueful\Notifications\Utils
 */
class NotificationResultParser
{
    /**
     * Parse notification service result into a standardized format
     *
     * @param array|null $result The result from NotificationService->send()
     * @param array $successData Additional data to include on success
     * @param string $successMessage Custom success message
     * @param string $defaultErrorMessage Default error message if no specific error is found
     * @param string $channelName The channel name to check for specific errors (e.g., 'email')
     * @return array Standardized result with success, message, and error_code fields
     */
    public static function parse(
        $result,
        array $successData = [],
        string $successMessage = 'Notification sent successfully',
        string $defaultErrorMessage = 'Failed to send notification',
        string $channelName = 'email'
    ): array {
        // Handle case where result is not an array
        if (!is_array($result)) {
            return [
                'success' => false,
                'message' => 'Invalid response from notification service',
                'error_code' => 'invalid_notification_response'
            ];
        }

        // Success case
        if (isset($result['status']) && $result['status'] === 'success') {
            return array_merge([
                'success' => true,
                'message' => $successMessage
            ], $successData);
        }

        // Error cases
        $errorCode = 'notification_send_failure';
        $errorMessage = $defaultErrorMessage;

        // Extract more specific error information if available
        if (isset($result['status'])) {
            switch ($result['status']) {
                case 'deferred':
                    $errorCode = 'notification_send_deferred';
                    $errorMessage = 'Notification has been scheduled for later delivery.';
                    break;

                case 'skipped':
                    $errorCode = 'notification_send_skipped';
                    if (isset($result['reason'])) {
                        $errorMessage = 'Notification skipped: ' . $result['reason'];
                    }
                    break;

                case 'failed':
                    $errorCode = 'notification_send_failed';

                    // Check if we have channel-specific information
                    if (isset($result['channels']) && isset($result['channels'][$channelName])) {
                        $channel = $result['channels'][$channelName];

                        if (isset($channel['reason'])) {
                            switch ($channel['reason']) {
                                case 'channel_not_found':
                                    $errorCode = $channelName . '_provider_not_configured';
                                    $errorMessage = ucfirst($channelName) . ' provider is not properly configured.';
                                    break;

                                case 'channel_unavailable':
                                    $errorCode = $channelName . '_service_unavailable';
                                    $errorMessage = ucfirst($channelName) . ' service is currently unavailable.';
                                    break;

                                case 'recipient_opted_out':
                                    $errorCode = 'recipient_opted_out';
                                    $errorMessage = 'Recipient has opted out of ' . $channelName . ' notifications.';
                                    break;

                                case 'send_failed':
                                    $errorCode = $channelName . '_delivery_failed';
                                    $errorMessage = 'Failed to deliver ' . $channelName . ' to the recipient.';
                                    break;

                                case 'exception':
                                    $errorCode = $channelName . '_system_error';
                                    if (isset($channel['message'])) {
                                        $errorMessage = 'System error: ' . $channel['message'];
                                    } else {
                                        $errorMessage = 'An unexpected error occurred while sending the ' . 
                                            $channelName . '.';
                                    }
                                    break;
                            }
                        }
                    } elseif (isset($result['reason'])) {
                        if ($result['reason'] === 'no_channels') {
                            $errorCode = 'no_delivery_channels';
                            $errorMessage = 'No delivery channels are available.';
                        }
                    }
                    break;
            }
        }

        return [
            'success' => false,
            'message' => $errorMessage,
            'error_code' => $errorCode
        ];
    }

    /**
     * Parse email notification result with email-specific messages
     *
     * @param array|null $result The result from NotificationService->send()
     * @param array $successData Additional data to include on success
     * @param string $successMessage Custom success message
     * @return array Standardized result with success, message, and error_code fields
     */
    public static function parseEmailResult(
        $result,
        array $successData = [],
        string $successMessage = 'Email sent successfully'
    ): array {
        return self::parse(
            $result,
            $successData,
            $successMessage,
            'Failed to send email. Please try again later.',
            'email'
        );
    }

    /**
     * Parse SMS notification result with SMS-specific messages
     *
     * @param array|null $result The result from NotificationService->send()
     * @param array $successData Additional data to include on success
     * @param string $successMessage Custom success message
     * @return array Standardized result with success, message, and error_code fields
     */
    public static function parseSmsResult(
        $result,
        array $successData = [],
        string $successMessage = 'SMS sent successfully'
    ): array {
        return self::parse(
            $result,
            $successData,
            $successMessage,
            'Failed to send SMS. Please try again later.',
            'sms'
        );
    }

    /**
     * Parse push notification result with push-specific messages
     *
     * @param array|null $result The result from NotificationService->send()
     * @param array $successData Additional data to include on success
     * @param string $successMessage Custom success message
     * @return array Standardized result with success, message, and error_code fields
     */
    public static function parsePushResult(
        $result,
        array $successData = [],
        string $successMessage = 'Push notification sent successfully'
    ): array {
        return self::parse(
            $result,
            $successData,
            $successMessage,
            'Failed to send push notification. Please try again later.',
            'push'
        );
    }
}
