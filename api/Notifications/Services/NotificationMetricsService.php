<?php
declare(strict_types=1);

namespace Glueful\Notifications\Services;

use Glueful\Cache\CacheFactory;
use DateTime;
use Glueful\Logging\LogManager;

/**
 * Notification Metrics Service
 * 
 * Centralized service for tracking and reporting notification performance metrics
 * across all notification channels.
 * 
 * @package Glueful\Notifications\Services
 */
class NotificationMetricsService
{
    /**
     * @var object Cache instance for metrics storage
     */
    private object $cache;
    
    /**
     * @var LogManager|null Logger instance
     */
    private ?LogManager $logger;
    
    /**
     * @var array Configuration options
     */
    private array $config;
    
    /**
     * NotificationMetricsService constructor
     * 
     * @param LogManager|null $logger Logger instance
     * @param array $config Configuration options
     */
    public function __construct(?LogManager $logger = null, array $config = [])
    {
        $this->logger = $logger;
        $this->config = $config;
        
        // Initialize cache for metrics using the static create method
        $this->cache = CacheFactory::create();
    }
    
    /**
     * Store notification creation time for calculating delivery time later
     * 
     * @param string $notificationId Notification ID
     * @param string $channel Notification channel
     * @return void
     */
    public function setNotificationCreationTime(string $notificationId, string $channel): void
    {
        $key = "notification:{$channel}:{$notificationId}:created_at";
        $this->cache->set($key, time(), 86400); // Store for 24 hours
    }
    
    /**
     * Get notification creation time 
     * 
     * @param string $notificationId Notification ID
     * @param string $channel Notification channel
     * @return int|null Timestamp when notification was created or null if not found
     */
    public function getNotificationCreationTime(string $notificationId, string $channel): ?int
    {
        $key = "notification:{$channel}:{$notificationId}:created_at";
        return $this->cache->get($key);
    }
    
    /**
     * Track delivery time for performance metrics
     * 
     * @param string $notificationId Notification ID
     * @param string $channel Notification channel
     * @param int $deliveryTime Delivery time in seconds
     * @return void
     */
    public function trackDeliveryTime(string $notificationId, string $channel, int $deliveryTime): void
    {
        // Store delivery time for this specific notification
        $key = "notification:{$channel}:{$notificationId}:delivery_time";
        $this->cache->set($key, $deliveryTime, 86400);
        
        // Update running metrics
        $this->updateAverageDeliveryTime($channel, $deliveryTime);
    }
    
    /**
     * Update the average delivery time metric
     * 
     * @param string $channel Notification channel
     * @param int $newDeliveryTime New delivery time to include in average
     * @return void
     */
    private function updateAverageDeliveryTime(string $channel, int $newDeliveryTime): void
    {
        $key = "notification_metrics:{$channel}:avg_delivery_time";
        $countKey = "notification_metrics:{$channel}:delivery_time_count";
        $sumKey = "notification_metrics:{$channel}:delivery_time_sum";
        
        // Get current count and sum
        $count = (int)$this->cache->get($countKey, 0);
        $sum = (float)$this->cache->get($sumKey, 0);
        
        // Update count and sum
        $count++;
        $sum += $newDeliveryTime;
        
        // Calculate and store new average
        $avg = $sum / $count;
        
        $this->cache->set($countKey, $count, 0); // No expiration
        $this->cache->set($sumKey, $sum, 0);
        $this->cache->set($key, $avg, 0);
    }
    
    /**
     * Get the average delivery time
     * 
     * @param string $channel Notification channel
     * @return float Average delivery time in seconds
     */
    public function getAverageDeliveryTime(string $channel): float
    {
        $key = "notification_metrics:{$channel}:avg_delivery_time";
        return (float)$this->cache->get($key, 0);
    }
    
    /**
     * Increment and get retry count for a notification
     * 
     * @param string $notificationId Notification ID
     * @param string $channel Notification channel
     * @return int Current retry count after increment
     */
    public function incrementRetryCount(string $notificationId, string $channel): int
    {
        $key = "notification:{$channel}:{$notificationId}:retry_count";
        $retryCount = (int)$this->cache->get($key, 0);
        $retryCount++;
        $this->cache->set($key, $retryCount, 86400);
        
        // Update retry distribution metrics
        $this->updateRetryDistribution($channel, $retryCount);
        
        return $retryCount;
    }
    
    /**
     * Get retry count for a notification
     * 
     * @param string $notificationId Notification ID
     * @param string $channel Notification channel
     * @return int Current retry count
     */
    public function getRetryCount(string $notificationId, string $channel): int
    {
        $key = "notification:{$channel}:{$notificationId}:retry_count";
        return (int)$this->cache->get($key, 0);
    }
    
    /**
     * Update retry distribution metrics
     * 
     * @param string $channel Notification channel
     * @param int $retryCount Retry count to increment
     * @return void
     */
    private function updateRetryDistribution(string $channel, int $retryCount): void
    {
        $key = "notification_metrics:{$channel}:retry_distribution:{$retryCount}";
        $count = (int)$this->cache->get($key, 0);
        $count++;
        $this->cache->set($key, $count, 0); // No expiration
    }
    
    /**
     * Get retry distribution data
     * 
     * @param string $channel Notification channel
     * @param int $maxRetries Maximum retry count to report
     * @return array Retry distribution counts
     */
    public function getRetryDistribution(string $channel, int $maxRetries = 3): array
    {
        $distribution = [];
        
        for ($i = 1; $i <= $maxRetries; $i++) {
            $key = "notification_metrics:{$channel}:retry_distribution:{$i}";
            $distribution[$i] = (int)$this->cache->get($key, 0);
        }
        
        return $distribution;
    }
    
    /**
     * Update success/failure rate metrics
     * 
     * @param string $channel Notification channel
     * @param bool $success Whether the notification was successful
     * @return void
     */
    public function updateSuccessRateMetrics(string $channel, bool $success): void
    {
        $totalKey = "notification_metrics:{$channel}:total_attempts";
        $successKey = "notification_metrics:{$channel}:successful_attempts";
        
        $total = (int)$this->cache->get($totalKey, 0);
        $successful = (int)$this->cache->get($successKey, 0);
        
        $total++;
        if ($success) {
            $successful++;
        }
        
        $this->cache->set($totalKey, $total, 0); // No expiration
        $this->cache->set($successKey, $successful, 0);
    }
    
    /**
     * Get success rate
     * 
     * @param string $channel Notification channel
     * @return float Success rate percentage
     */
    public function getSuccessRate(string $channel): float
    {
        $totalKey = "notification_metrics:{$channel}:total_attempts";
        $successKey = "notification_metrics:{$channel}:successful_attempts";
        
        $total = (int)$this->cache->get($totalKey, 0);
        $successful = (int)$this->cache->get($successKey, 0);
        
        if ($total === 0) {
            return 100.0; // Default to 100% if no attempts
        }
        
        return round(($successful / $total) * 100, 2);
    }
    
    /**
     * Get total sent notifications count
     * 
     * @param string $channel Notification channel
     * @return int Count of successfully sent notifications
     */
    public function getTotalSent(string $channel): int
    {
        $key = "notification_metrics:{$channel}:successful_attempts";
        return (int)$this->cache->get($key, 0);
    }
    
    /**
     * Get total failed notifications count (after all retries)
     * 
     * @param string $channel Notification channel
     * @return int Count of failed notifications
     */
    public function getTotalFailed(string $channel): int
    {
        $totalKey = "notification_metrics:{$channel}:total_attempts";
        $successKey = "notification_metrics:{$channel}:successful_attempts";
        
        $total = (int)$this->cache->get($totalKey, 0);
        $successful = (int)$this->cache->get($successKey, 0);
        
        return $total - $successful;
    }
    
    /**
     * Clean up notification-specific metrics data after processing is complete
     * 
     * @param string $notificationId Notification ID
     * @param string $channel Notification channel
     * @return void
     */
    public function cleanupNotificationMetrics(string $notificationId, string $channel): void
    {
        $createdKey = "notification:{$channel}:{$notificationId}:created_at";
        $deliveryTimeKey = "notification:{$channel}:{$notificationId}:delivery_time";
        $retryCountKey = "notification:{$channel}:{$notificationId}:retry_count";
        
        $this->cache->delete($createdKey);
        $this->cache->delete($deliveryTimeKey);
        $this->cache->delete($retryCountKey);
    }
    
    /**
     * Get all performance metrics for a specific channel
     * 
     * @param string $channel Notification channel
     * @param int $maxRetries Maximum number of retries to report in distribution
     * @return array Metrics including average delivery time, success rate, etc.
     */
    public function getChannelMetrics(string $channel, int $maxRetries = 3): array
    {
        $metrics = [
            'channel' => $channel,
            'avg_delivery_time_ms' => $this->getAverageDeliveryTime($channel) * 1000,
            'success_rate_percent' => $this->getSuccessRate($channel),
            'total_sent' => $this->getTotalSent($channel),
            'total_failed' => $this->getTotalFailed($channel),
            'retry_distribution' => $this->getRetryDistribution($channel, $maxRetries),
            'timestamp' => (new DateTime())->format('Y-m-d H:i:s')
        ];
        
        return $metrics;
    }
    
    /**
     * Get metrics for all channels
     * 
     * @param array $channels List of channels to get metrics for
     * @return array Metrics for all channels
     */
    public function getAllMetrics(array $channels = []): array
    {
        $allMetrics = [
            'timestamp' => (new DateTime())->format('Y-m-d H:i:s'),
            'channels' => []
        ];
        
        // Get metrics for each channel
        foreach ($channels as $channel) {
            $allMetrics['channels'][$channel] = $this->getChannelMetrics($channel);
        }
        
        // Calculate aggregate metrics across all channels
        if (!empty($channels)) {
            $totalSent = 0;
            $totalFailed = 0;
            $totalAttempts = 0;
            $weightedDeliveryTime = 0;
            
            foreach ($channels as $channel) {
                $channelMetrics = $allMetrics['channels'][$channel];
                $sent = $channelMetrics['total_sent'];
                $failed = $channelMetrics['total_failed'];
                
                $totalSent += $sent;
                $totalFailed += $failed;
                $totalAttempts += ($sent + $failed);
                
                // Weight delivery time by number of successful deliveries
                if ($sent > 0) {
                    $weightedDeliveryTime += ($channelMetrics['avg_delivery_time_ms'] * $sent);
                }
            }
            
            $allMetrics['aggregate'] = [
                'total_sent' => $totalSent,
                'total_failed' => $totalFailed,
                'success_rate_percent' => $totalAttempts > 0 ? round(($totalSent / $totalAttempts) * 100, 2) : 100.0,
                'avg_delivery_time_ms' => $totalSent > 0 ? round($weightedDeliveryTime / $totalSent, 2) : 0
            ];
        }
        
        return $allMetrics;
    }
    
    /**
     * Reset all metrics for a specific channel
     * 
     * @param string $channel Notification channel
     * @return bool Success status
     */
    public function resetChannelMetrics(string $channel): bool
    {
        try {
            // List of metric keys to reset
            $keys = [
                "notification_metrics:{$channel}:avg_delivery_time",
                "notification_metrics:{$channel}:delivery_time_count",
                "notification_metrics:{$channel}:delivery_time_sum",
                "notification_metrics:{$channel}:total_attempts",
                "notification_metrics:{$channel}:successful_attempts"
            ];
            
            // Add retry distribution keys
            for ($i = 1; $i <= 10; $i++) { // Assume max 10 retries to be safe
                $keys[] = "notification_metrics:{$channel}:retry_distribution:{$i}";
            }
            
            // Delete all keys
            foreach ($keys as $key) {
                $this->cache->delete($key);
            }
            
            return true;
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error("Failed to reset metrics for channel {$channel}: " . $e->getMessage());
            }
            return false;
        }
    }
}