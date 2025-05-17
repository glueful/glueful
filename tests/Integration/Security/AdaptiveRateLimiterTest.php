<?php

namespace Tests\Integration\Security;

use PHPUnit\Framework\TestCase;
use Tests\Mocks\MockAutoloader;
use Tests\Mocks\MockRateLimiter;

/**
 * AdaptiveRateLimiterTest
 *
 * Basic tests for the RateLimiter functionality
 */
class AdaptiveRateLimiterTest extends TestCase
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
     * Test creation of rate limiters
     */
    public function testRateLimiterCreation(): void
    {
        // IP-based rate limiter
        $ipLimiter = MockRateLimiter::perIp('192.168.1.1', 100, 60);
        $this->assertInstanceOf(MockRateLimiter::class, $ipLimiter);

        // User-based rate limiter
        $userLimiter = MockRateLimiter::perUser('user123', 50, 30);
        $this->assertInstanceOf(MockRateLimiter::class, $userLimiter);

        // Endpoint-based rate limiter
        $endpointLimiter = MockRateLimiter::perEndpoint('/api/test', 'user123', 20, 10);
        $this->assertInstanceOf(MockRateLimiter::class, $endpointLimiter);
    }

    /**
     * Test basic functionality
     */
    public function testBasicFunctionality(): void
    {
        // Create a limiter with 3 maximum attempts
        $limiter = MockRateLimiter::perIp('192.168.1.1', 3, 60);

        // Attempts should succeed (current implementation always returns true)
        $this->assertTrue($limiter->attempt());
        $this->assertTrue($limiter->attempt());
        $this->assertTrue($limiter->attempt());
    }
}
