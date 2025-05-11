<?php

namespace Tests\Unit\Http\Middleware;

use Tests\TestCase;
use Glueful\Http\Middleware\RateLimiterMiddleware;
use Glueful\Http\Middleware\RequestHandlerInterface;
use Tests\Unit\Mocks\MocksStatic;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

// Include our RateLimiter override for testing
require_once __DIR__ . '/../../Mocks/RateLimiterOverride.php';
use Glueful\Security\RateLimiter;


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
        
        // Create a mock RateLimiter that we'll use for all tests
        $this->mockRateLimiter = $this->createMock(RateLimiter::class);
        
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
        $this->mockRateLimiter->method('isExceeded')->willReturn(false);
        $this->mockRateLimiter->method('attempt')->willReturn(true);
        $this->mockRateLimiter->method('remaining')->willReturn(59);
        
        // Set up the static mock for perIp
        $self = $this;
        $this->mockStaticMethod(RateLimiter::class, 'perIp', function($ip, $maxAttempts, $windowSeconds) use ($self) {
            return $self->mockRateLimiter;
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
        $this->mockRateLimiter->method('isExceeded')->willReturn(true);
        $this->mockRateLimiter->method('getRetryAfter')->willReturn(30);
        
        // Set up the static mock for perIp
        $self = $this;
        $this->mockStaticMethod(RateLimiter::class, 'perIp', function($ip, $maxAttempts, $windowSeconds) use ($self) {
            return $self->mockRateLimiter;
        });
        
        // Create the middleware
        $middleware = new RateLimiterMiddleware();
        
        // Create a mock request
        $request = new Request();
        $request->server->set('REMOTE_ADDR', '192.168.1.1');
        
        // Create a mock handler that should not be called
        $mockHandler = $this->createMockHandler(null, false);
        
        // Process the request
        $response = $middleware->process($request, $mockHandler);
        
        // Verify the response is a 429 Too Many Requests
        $this->assertEquals(429, $response->getStatusCode());
        
        // Verify appropriate headers are set
        $this->assertEquals('60', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('0', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertEquals('30', $response->headers->get('Retry-After'));
    }
    
    /**
     * Test user-based rate limiting when authenticated
     */
    public function testUserBasedRateLimiting(): void
    {
        // Configure the mock RateLimiter
        $this->mockRateLimiter->method('isExceeded')->willReturn(false);
        $this->mockRateLimiter->method('attempt')->willReturn(true);
        $this->mockRateLimiter->method('remaining')->willReturn(99);
        
        // Set up the static mock for perUser
        $self = $this;
        $this->mockStaticMethod(RateLimiter::class, 'perUser', function($userId, $maxAttempts, $windowSeconds) use ($self) {
            return $self->mockRateLimiter;
        });
        
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
        $this->mockRateLimiter->method('isExceeded')->willReturn(false);
        $this->mockRateLimiter->method('attempt')->willReturn(true);
        $this->mockRateLimiter->method('remaining')->willReturn(4);
        
        // Set up the static mock for perIp
        $self = $this;
        $this->mockStaticMethod(RateLimiter::class, 'perIp', function($ip, $maxAttempts, $windowSeconds) use ($self) {
            return $self->mockRateLimiter;
        });
        
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
        $this->mockRateLimiter->method('isExceeded')->willReturn(false);
        $this->mockRateLimiter->method('attempt')->willReturn(true);
        $this->mockRateLimiter->method('remaining')->willReturn(29);
        
        // Set up the static mock for perIp since the middleware falls back to IP for unknown types
        $self = $this;
        $this->mockStaticMethod(RateLimiter::class, 'perIp', function($ip, $maxAttempts, $windowSeconds) use ($self) {
            return $self->mockRateLimiter;
        });
        
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
    }
    

}
