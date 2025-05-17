<?php

namespace Tests\Mocks;

use Glueful\Security\AdaptiveRateLimiter;

/**
 * Mock AdaptiveRateLimiter for testing without database dependencies
 *
 * This standalone implementation can be used to test rate limiting functionality
 * without relying on the actual AdaptiveRateLimiter class or its dependencies.
 */
class MockAdaptiveRateLimiter extends MockRateLimiter
{
    /** @var array In-memory storage for behavior profiles */
    protected static array $behaviorProfiles = [];

    /** @var array Custom behaviors for testing */
    protected static array $testAdaptiveBehaviors = [];

    /**
     * Constructor
     *
     * @param string $key Rate limiter key
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $windowSeconds Time window in seconds
     */
    public function __construct(string $key, int $maxAttempts, int $windowSeconds)
    {
        parent::__construct($key, $maxAttempts, $windowSeconds);

        if (!isset(self::$behaviorProfiles[$key])) {
            self::$behaviorProfiles[$key] = [
                'request_count' => 0,
                'last_seen' => time(),
                'first_seen' => time(),
                'intervals' => [],
                'anomaly_score' => 0.0
            ];
        }
    }

    /**
     * Get the behavior score for this rate limiter key
     *
     * @return float Score between 0 and 1 (higher is more suspicious)
     */
    public function getBehaviorScore(): float
    {
        // Allow overriding for tests
        if (isset(self::$testAdaptiveBehaviors[$this->key]['behaviorScore'])) {
            return self::$testAdaptiveBehaviors[$this->key]['behaviorScore'];
        }

        return self::$behaviorProfiles[$this->key]['anomaly_score'] ?? 0.0;
    }

    /**
     * Set behavior score for testing
     *
     * @param string $key Rate limiter key
     * @param float $score Behavior score (0-1)
     */
    public static function setBehaviorScore(string $key, float $score): void
    {
        if (!isset(self::$testAdaptiveBehaviors[$key])) {
            self::$testAdaptiveBehaviors[$key] = [];
        }
        self::$testAdaptiveBehaviors[$key]['behaviorScore'] = $score;
    }

    /**
     * Get active applicable rules based on current behavior score
     *
     * @return array Empty array for testing
     */
    public function getActiveApplicableRules(): array
    {
        // For testing, just return an empty array
        return [];
    }

    /**
     * Check if rate limit is exceeded
     *
     * @return bool True if rate limit is exceeded
     */
    public function isExceeded(): bool
    {
        // Allow overriding for tests
        if (isset(self::$testAdaptiveBehaviors[$this->key]['isExceeded'])) {
            return self::$testAdaptiveBehaviors[$this->key]['isExceeded'];
        }

        return parent::isExceeded();
    }

    /**
     * Get remaining attempts
     *
     * @return int Number of attempts remaining
     */
    public function remaining(): int
    {
        // Allow overriding for tests
        if (isset(self::$testAdaptiveBehaviors[$this->key]['remaining'])) {
            return self::$testAdaptiveBehaviors[$this->key]['remaining'];
        }

        return parent::remaining();
    }

    /**
     * Get retry after time
     *
     * @return int Seconds to wait before retry
     */
    public function getRetryAfter(): int
    {
        // Allow overriding for tests
        if (isset(self::$testAdaptiveBehaviors[$this->key]['retryAfter'])) {
            return self::$testAdaptiveBehaviors[$this->key]['retryAfter'];
        }

        return parent::getRetryAfter();
    }

    /**
     * Set isExceeded result for testing
     *
     * @param string $key Rate limiter key
     * @param bool $isExceeded Is exceeded result
     */
    public static function setAdaptiveIsExceeded(string $key, bool $isExceeded): void
    {
        if (!isset(self::$testAdaptiveBehaviors[$key])) {
            self::$testAdaptiveBehaviors[$key] = [];
        }
        self::$testAdaptiveBehaviors[$key]['isExceeded'] = $isExceeded;
    }

    /**
     * Set remaining attempts for testing
     *
     * @param string $key Rate limiter key
     * @param int $remaining Remaining attempts
     */
    public static function setAdaptiveRemaining(string $key, int $remaining): void
    {
        if (!isset(self::$testAdaptiveBehaviors[$key])) {
            self::$testAdaptiveBehaviors[$key] = [];
        }
        self::$testAdaptiveBehaviors[$key]['remaining'] = $remaining;
    }

    /**
     * Set retry after time for testing
     *
     * @param string $key Rate limiter key
     * @param int $seconds Seconds to wait before retry
     */
    public static function setAdaptiveRetryAfter(string $key, int $seconds): void
    {
        if (!isset(self::$testAdaptiveBehaviors[$key])) {
            self::$testAdaptiveBehaviors[$key] = [];
        }
        self::$testAdaptiveBehaviors[$key]['retryAfter'] = $seconds;
    }

    /**
     * Reset all test behaviors
     */
    public static function resetMock(): void
    {
        self::$behaviorProfiles = [];
        self::$testAdaptiveBehaviors = [];
        parent::resetMock();
    }
}
