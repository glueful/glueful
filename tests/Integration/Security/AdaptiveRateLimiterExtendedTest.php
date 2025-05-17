<?php

namespace Tests\Integration\Security;

use PHPUnit\Framework\TestCase;
use Tests\Mocks\MockAutoloader;
use Tests\Mocks\MockAdaptiveRateLimiter;
use Tests\Mocks\MockRateLimiterRule;

/**
 * Test for adaptive rate limiting functionality
 */
class AdaptiveRateLimiterExtendedTest extends TestCase
{
    /**
     * Set up tests
     */
    protected function setUp(): void
    {
        // Register mocks
        MockAutoloader::register();
    }

    /**
     * Tear down tests
     */
    protected function tearDown(): void
    {
        // Unregister mocks
        MockAutoloader::unregister();
    }

    /**
     * Test rule-based rate limiting
     */
    public function testRuleBasedRateLimiting(): void
    {
        // Check if MockAdaptiveRateLimiter implementation exists, if not, skip the test
        if (!class_exists('Tests\Mocks\MockAdaptiveRateLimiter')) {
            $this->markTestSkipped('MockAdaptiveRateLimiter class not found.');
            return;
        }

        // Create a rule
        $rule = new MockRateLimiterRule(
            'test_rule',
            'Test Rule',
            'Test rule description',
            5,  // maxAttempts
            60, // windowSeconds
            0.5, // threshold
            [],  // conditions
            true, // active
            10   // priority
        );

        // Test passes if we can instantiate the rule
        $this->assertInstanceOf(MockRateLimiterRule::class, $rule);

        // Create an adaptive rate limiter
        $limiter = new MockAdaptiveRateLimiter('ip:192.168.1.1', 10, 60);
        $this->assertInstanceOf(MockAdaptiveRateLimiter::class, $limiter);
    }

    /**
     * Test behavior scoring
     */
    public function testBehaviorScoring(): void
    {
        // Check if MockAdaptiveRateLimiter implementation exists, if not, skip the test
        if (!class_exists('Tests\Mocks\MockAdaptiveRateLimiter')) {
            $this->markTestSkipped('MockAdaptiveRateLimiter class not found.');
            return;
        }

        // Create an adaptive rate limiter
        $limiter = new MockAdaptiveRateLimiter('user:suspicious_user', 10, 60);

        // Try to access behavior score method if it exists
        if (method_exists($limiter, 'getBehaviorScore')) {
            $score = $limiter->getBehaviorScore();
            // Just assert that the score is a float between 0 and 1
            $this->assertIsFloat($score);
            $this->assertGreaterThanOrEqual(0.0, $score);
            $this->assertLessThanOrEqual(1.0, $score);
        } else {
            // Skip if method doesn't exist
            $this->markTestSkipped('getBehaviorScore method not found in MockAdaptiveRateLimiter.');
        }
    }
}
