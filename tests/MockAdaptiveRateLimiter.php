<?php

// Simplified implementation of the Adaptive Rate Limiter for testing purposes

namespace Glueful\Security\Mock;

/**
 * Mock AdaptiveRateLimiter for testing without database dependencies
 */
class AdaptiveRateLimiter
{
    /** @var string Rate limiter key */
    protected string $key;

    /** @var int Maximum attempts allowed */
    protected int $maxAttempts;

    /** @var int Time window in seconds */
    protected int $windowSeconds;

    /** @var array In-memory storage for attempts */
    protected static array $attempts = [];

    /** @var array In-memory storage for behavior profiles */
    protected static array $behaviorProfiles = [];

    /**
     * Constructor
     *
     * @param string $key Rate limiter key
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $windowSeconds Time window in seconds
     */
    public function __construct(string $key, int $maxAttempts, int $windowSeconds)
    {
        $this->key = $key;
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;

        if (!isset(self::$attempts[$key])) {
            self::$attempts[$key] = [];
        }
    }

    /**
     * Create an IP-based rate limiter
     *
     * @param string $ip IP address
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return static New rate limiter instance
     */
    public static function perIp(string $ip, int $maxAttempts, int $windowSeconds): self
    {
        return new self("ip:$ip", $maxAttempts, $windowSeconds);
    }

    /**
     * Create a user-based rate limiter
     *
     * @param string $userId User ID
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return static New rate limiter instance
     */
    public static function perUser(string $userId, int $maxAttempts, int $windowSeconds): self
    {
        return new self("user:$userId", $maxAttempts, $windowSeconds);
    }

    /**
     * Create an endpoint-based rate limiter
     *
     * @param string $endpoint Endpoint path
     * @param string $identifier User or IP identifier
     * @param int $maxAttempts Maximum attempts allowed
     * @param int $windowSeconds Time window in seconds
     * @return static New rate limiter instance
     */
    public static function perEndpoint(string $endpoint, string $identifier, int $maxAttempts, int $windowSeconds): self
    {
        return new self("endpoint:{$endpoint}:{$identifier}", $maxAttempts, $windowSeconds);
    }

    /**
     * Record an attempt and check if it's allowed
     *
     * @return bool True if the attempt is allowed, false if rate limited
     */
    public function attempt(): bool
    {
        // Clean up old attempts
        $this->cleanupOldAttempts();

        // Get current attempts count
        $currentCount = count(self::$attempts[$this->key]);

        // Check if we're over the limit
        if ($currentCount >= $this->maxAttempts) {
            $this->recordBehavior('over_limit');
            return false;
        }

        // Record this attempt
        self::$attempts[$this->key][] = microtime(true);

        // Update behavior profile
        $this->recordBehavior('normal');

        return true;
    }

    /**
     * Clean up attempts older than the time window
     */
    protected function cleanupOldAttempts(): void
    {
        $now = microtime(true);
        $cutoff = $now - $this->windowSeconds;

        self::$attempts[$this->key] = array_filter(
            self::$attempts[$this->key],
            function ($timestamp) use ($cutoff) {
                return $timestamp >= $cutoff;
            }
        );
    }

    /**
     * Get number of attempts made within the time window
     *
     * @return int Attempt count
     */
    public function getAttemptCount(): int
    {
        $this->cleanupOldAttempts();
        return count(self::$attempts[$this->key]);
    }

    /**
     * Record behavior for profiling
     *
     * @param string $type Behavior type
     */
    protected function recordBehavior(string $type): void
    {
        if (!isset(self::$behaviorProfiles[$this->key])) {
            self::$behaviorProfiles[$this->key] = [
                'request_count' => 0,
                'last_seen' => time(),
                'first_seen' => time(),
                'intervals' => [],
                'anomaly_score' => 0.0
            ];
        }

        $profile = &self::$behaviorProfiles[$this->key];
        $now = microtime(true);

        // Record interval if this isn't the first request
        if (isset($profile['last_request_time'])) {
            $interval = $now - $profile['last_request_time'];
            $profile['intervals'][] = $interval;

            // Keep only the last 10 intervals
            if (count($profile['intervals']) > 10) {
                array_shift($profile['intervals']);
            }

            // Calculate statistics
            if (count($profile['intervals']) > 1) {
                $profile['avg_interval'] = array_sum($profile['intervals']) / count($profile['intervals']);

                // Simple anomaly scoring based on rapid requests
                $rapidRequests = array_filter($profile['intervals'], function ($i) {
                    return $i < 0.5;
                });
                $profile['rapid_request_ratio'] = count($rapidRequests) / count($profile['intervals']);

                // Higher score means more suspicious
                $profile['anomaly_score'] = min(1.0, $profile['rapid_request_ratio'] * 1.5);
            }
        }

        // Update profile
        $profile['request_count']++;
        $profile['last_seen'] = time();
        $profile['last_request_time'] = $now;
    }

    /**
     * Get the behavior score for this rate limiter key
     *
     * @return float Score between 0 and 1 (higher is more suspicious)
     */
    public function getBehaviorScore(): float
    {
        return self::$behaviorProfiles[$this->key]['anomaly_score'] ?? 0.0;
    }

    /**
     * Update the behavior profile with new signals
     *
     * @param array $signals Behavior signals to update
     * @return void
     */
    public function updateBehaviorProfile(array $signals): void
    {
        if (!isset(self::$behaviorProfiles[$this->key])) {
            self::$behaviorProfiles[$this->key] = [
                'request_count' => 0,
                'last_seen' => time(),
                'first_seen' => time(),
                'intervals' => [],
                'anomaly_score' => 0.0
            ];
        }

        $profile = &self::$behaviorProfiles[$this->key];

        // Apply signals to adjust the anomaly score
        $scoreAdjustment = 0;

        if (isset($signals['rapid_fire']) && $signals['rapid_fire']) {
            $scoreAdjustment += 0.3;
        }

        if (isset($signals['unusual_patterns']) && $signals['unusual_patterns']) {
            $scoreAdjustment += 0.2;
        }

        if (isset($signals['unusual_headers']) && $signals['unusual_headers']) {
            $scoreAdjustment += 0.2;
        }

        if (isset($signals['ip_changing']) && $signals['ip_changing']) {
            $scoreAdjustment += 0.3;
        }

        if (isset($signals['failed_logins'])) {
            $scoreAdjustment += min(0.5, $signals['failed_logins'] * 0.1);
        }

        // Positive signals reduce the score
        if (isset($signals['successful_logins'])) {
            $scoreAdjustment -= min(0.3, $signals['successful_logins'] * 0.03);
        }

        if (isset($signals['verified_device']) && $signals['verified_device']) {
            $scoreAdjustment -= 0.2;
        }

        // Update the score within bounds (0.0 to 1.0)
        $profile['anomaly_score'] = max(0.0, min(1.0, $profile['anomaly_score'] + $scoreAdjustment));
    }

    /**
     * Get seconds until rate limit resets
     *
     * @return int Seconds until reset
     */
    public function getSecondsUntilReset(): int
    {
        $this->cleanupOldAttempts();

        if (empty(self::$attempts[$this->key])) {
            return 0;
        }

        // Find the oldest attempt
        $oldest = min(self::$attempts[$this->key]);
        $now = microtime(true);

        // Calculate when it expires
        $expiry = $oldest + $this->windowSeconds;
        $remaining = max(0, $expiry - $now);

        return (int) ceil($remaining);
    }

    /**
     * Get maximum attempts allowed
     *
     * @return int Maximum attempts
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * Get window seconds
     *
     * @return int Window seconds
     */
    public function getWindowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * Create a rate limiter from a rule definition
     *
     * @param array $rule Rule definition
     * @return static New rate limiter instance
     */
    public static function fromRule(array $rule): self
    {
        $limiter = new self(
            $rule['id'] ?? 'rule:' . uniqid(),
            $rule['max_attempts'] ?? 10,
            $rule['window_seconds'] ?? 60
        );

        // Store the threshold with the limiter
        self::$behaviorProfiles[$limiter->key]['threshold'] = $rule['threshold'] ?? 0.5;

        return $limiter;
    }
}
