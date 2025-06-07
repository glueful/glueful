<?php

namespace Tests\Unit\Http\Middleware;

use Tests\TestCase;
use Glueful\Http\Middleware\RateLimiterMiddleware;
use Glueful\Http\Middleware\RequestHandlerInterface;
use Tests\Unit\Mocks\MocksStatic;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Mocks\MockAutoloader;
use Tests\Mocks\RateLimiterAdapter;
use Glueful\Security\RateLimiter;
use Glueful\Security\AdaptiveRateLimiter;

/**
 * Tests for the Rate Limiter Middleware
 *
 * These tests verify that:
 * - Rate limiting properly restricts excessive requests
 * - Rate limit headers are correctly applied
 * - Different rate limiting strategies work as expected
 */
class RateLimiterMiddlewareTest extends TestCase
{
    use MocksStatic;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $mockRateLimiter;

    /**
     * Set up test environment
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Register mock classes using MockAutoloader
        MockAutoloader::register();

        // Create a mock RateLimiter that we'll use for all tests
        $this->mockRateLimiter = new \Tests\Mocks\MockRateLimiter("test:limiter", 60, 60);

        // Initialize the global mocks array
        if (!isset($GLOBALS['MOCK_STATIC_IMPLEMENTATIONS'])) {
            $GLOBALS['MOCK_STATIC_IMPLEMENTATIONS'] = [];
        }

        // Reset any static mocks from previous tests
        $this->resetStaticMocks();
    }

    /**
     * Test that requests below the limit pass through
     */
    public function testAllowsRequestsBelowLimit(): void
    {
        // Configure the mock RateLimiter
        $this->mockRateLimiter = new \Tests\Mocks\MockRateLimiter("test:limiter", 60, 60);

        // Force specific behavior for test
        \Tests\Mocks\MockRateLimiter::setIsExceeded("test:limiter", false);
        \Tests\Mocks\MockRateLimiter::setRemaining("test:limiter", 59);

        // Set up the static mock for perIp
        $self = $this;
        $this->mockStaticMethod(RateLimiter::class, 'perIp', function ($ip, $maxAttempts, $windowSeconds) use ($self) {
            return RateLimiterAdapter::castToRateLimiter($self->mockRateLimiter);
        });

        // Create the middleware
        $middleware = new RateLimiterMiddleware();

        // Create a mock request
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        // Create a mock handler that returns a response
        $expectedResponse = new Response('Success');
        $mockHandler = $this->createMockHandler($expectedResponse);

        // Process the request
        $response = $middleware->process($request, $mockHandler);

        // Verify the handler was called and the expected response was returned
        $this->assertSame($expectedResponse, $response);

        // Verify rate limit headers are set
        $this->assertEquals('60', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('59', $response->headers->get('X-RateLimit-Remaining'));
    }

    /**
     * Test that excessive requests are blocked
     */
    public function testBlocksExcessiveRequests(): void
    {
        // Configure the mock RateLimiter
        \Tests\Mocks\MockRateLimiter::setIsExceeded("ip:192.168.1.1", true);
        \Tests\Mocks\MockRateLimiter::setRetryAfter("ip:192.168.1.1", 30);

        // Set up the static mock for perIp
        $this->mockStaticMethod(RateLimiter::class, 'perIp', function ($ip, $maxAttempts, $windowSeconds) {
            $mockLimiter = \Tests\Mocks\MockRateLimiter::perIp($ip, $maxAttempts, $windowSeconds);
            return RateLimiterAdapter::castToRateLimiter($mockLimiter);
        });

        // Create the middleware
        $middleware = new RateLimiterMiddleware();

        // Create a mock request
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        // Create a mock handler that should not be called
        $mockHandler = $this->createMockHandler(null, false);

        // Expect RateLimitExceededException to be thrown instead of returning response
        $this->expectException(\Glueful\Exceptions\RateLimitExceededException::class);
        $this->expectExceptionMessage('Too Many Requests');

        // Process the request - should throw exception
        $middleware->process($request, $mockHandler);
    }

    /**
     * Test user-based rate limiting when authenticated
     */
    public function testUserBasedRateLimiting(): void
    {
        // Configure the mock RateLimiter
        $mockUserLimiter = new \Tests\Mocks\MockRateLimiter("user:user123", 100, 3600);
        \Tests\Mocks\MockRateLimiter::setIsExceeded("user:user123", false);
        \Tests\Mocks\MockRateLimiter::setRemaining("user:user123", 99);

        // Set up the static mock for perUser
        $this->mockStaticMethod(
            RateLimiter::class,
            'perUser',
            function ($userId, $maxAttempts, $windowSeconds) use ($mockUserLimiter) {
                return RateLimiterAdapter::castToRateLimiter($mockUserLimiter);
            }
        );

        // Create the middleware with user-based limiting
        $middleware = new RateLimiterMiddleware(100, 3600, 'user');

        // Create a mock authenticated request
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer test-token');

        // Mock the user authentication
        // We're setting a user attribute directly since we're not testing the auth flow
        $request->attributes->set('user_id', 'user123');

        // Create a mock handler
        $mockHandler = $this->createMockHandler();

        // Process the request
        $response = $middleware->process($request, $mockHandler);

        // Verify rate limit headers for the higher user-based limit
        $this->assertEquals('100', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('99', $response->headers->get('X-RateLimit-Remaining'));
    }

    /**
     * Test custom rate limit window
     */
    public function testCustomRateLimitWindow(): void
    {
        // Configure the mock RateLimiter
        $mockCustomLimiter = new \Tests\Mocks\MockRateLimiter("custom:limiter", 5, 3600);
        \Tests\Mocks\MockRateLimiter::setIsExceeded("custom:limiter", false);
        \Tests\Mocks\MockRateLimiter::setRemaining("custom:limiter", 4);

        // Set up the static mock for perIp
        $this->mockStaticMethod(
            RateLimiter::class,
            'perIp',
            function ($ip, $maxAttempts, $windowSeconds) use ($mockCustomLimiter) {
                return RateLimiterAdapter::castToRateLimiter($mockCustomLimiter);
            }
        );

        // Create the middleware with custom limits
        $middleware = new RateLimiterMiddleware(5, 3600); // 5 requests per hour

        // Create a mock request
        $request = new Request();

        // Create a mock handler
        $mockHandler = $this->createMockHandler();

        // Process the request
        $response = $middleware->process($request, $mockHandler);

        // Verify the custom rate limit is reflected in the headers
        $this->assertEquals('5', $response->headers->get('X-RateLimit-Limit'));
    }

    /**
     * Test endpoint-based rate limiting
     */
    public function testEndpointBasedRateLimiting(): void
    {
        // Configure the mock RateLimiter
        $mockEndpointLimiter = new \Tests\Mocks\MockRateLimiter("endpoint:limiter", 30, 60);
        \Tests\Mocks\MockRateLimiter::setIsExceeded("endpoint:limiter", false);
        \Tests\Mocks\MockRateLimiter::setRemaining("endpoint:limiter", 29);

        // Set up the static mock for perIp since the middleware falls back to IP for unknown types
        $this->mockStaticMethod(
            RateLimiter::class,
            'perIp',
            function ($ip, $maxAttempts, $windowSeconds) use ($mockEndpointLimiter) {
                return RateLimiterAdapter::castToRateLimiter($mockEndpointLimiter);
            }
        );

        // Create the middleware with endpoint-based limiting
        // Note: Currently the middleware doesn't have endpoint-based limiting implemented
        // so it will default to IP-based, but we test it with the 'endpoint' parameter
        // to make sure it doesn't break
        $middleware = new RateLimiterMiddleware(30, 60, 'endpoint');

        // Create a mock request
        $request = new Request();
        $request->server->set('REQUEST_URI', '/api/test/endpoint');
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        // Create a mock handler
        $mockHandler = $this->createMockHandler();

        // Process the request
        $response = $middleware->process($request, $mockHandler);

        // Verify rate limit headers
        $this->assertEquals('30', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('29', $response->headers->get('X-RateLimit-Remaining'));
    }

    /**
     * Test adaptive rate limiting
     */
    public function testAdaptiveRateLimiting(): void
    {
        // Create a mock AdaptiveRateLimiter
        $mockAdaptiveRateLimiter = new \Tests\Mocks\MockAdaptiveRateLimiter("ip:192.168.1.1", 50, 60);
        \Tests\Mocks\MockAdaptiveRateLimiter::setAdaptiveIsExceeded("ip:192.168.1.1", false);
        \Tests\Mocks\MockAdaptiveRateLimiter::setAdaptiveRemaining("ip:192.168.1.1", 49);
        \Tests\Mocks\MockAdaptiveRateLimiter::setBehaviorScore("ip:192.168.1.1", 0.2);

        // Set up the static mock for creating an AdaptiveRateLimiter
        $this->mockStaticMethod(
            RateLimiter::class,
            'perIp',
            function ($ip, $maxAttempts, $windowSeconds) use ($mockAdaptiveRateLimiter) {
                return RateLimiterAdapter::castToRateLimiter($mockAdaptiveRateLimiter);
            }
        );

        // Create the middleware with adaptive rate limiting enabled
        $middleware = new RateLimiterMiddleware(50, 60, 'ip', true);

        // Create a mock request
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '192.168.1.1');

        // Create a mock handler
        $mockHandler = $this->createMockHandler();

        // Process the request
        $response = $middleware->process($request, $mockHandler);

        // Verify rate limit headers
        $this->assertEquals('50', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('49', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertEquals('true', $response->headers->get('X-Adaptive-RateLimit'));
    }

    /**
     * Create a mock request handler for testing
     *
     * @param Response|null $response The response to return (or null for default)
     * @param bool $shouldBeCalled Whether the handler is expected to be called
     * @return RequestHandlerInterface&\PHPUnit\Framework\MockObject\MockObject A mock request handler
     */
    private function createMockHandler(?Response $response = null, bool $shouldBeCalled = true)
    {
        $mockHandler = $this->createMock(RequestHandlerInterface::class);

        if ($shouldBeCalled) {
            $expectation = $mockHandler->expects($this->once())->method('handle');
            $expectation->willReturn($response ?: new Response());
        } else {
            $mockHandler->expects($this->never())->method('handle');
        }

        return $mockHandler;
    }

    /**
     * Cleanup after each test
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        // Reset any static mocks
        $this->resetStaticMocks();

        // Unregister mock classes
        MockAutoloader::unregister();
    }
}
