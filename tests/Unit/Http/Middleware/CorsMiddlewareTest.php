<?php

namespace Tests\Unit\Http\Middleware;

use Tests\TestCase;
use Glueful\Http\Middleware\CorsMiddleware;
use Glueful\Http\Middleware\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests for the CORS Middleware
 * 
 * These tests verify that:
 * - CORS headers are correctly applied to responses
 * - Preflight requests are handled properly 
 * - Origin validation works as expected
 */
class CorsMiddlewareTest extends TestCase
{
    /**
     * Test that basic CORS headers are applied with default configuration
     */
    public function testAppliesDefaultCorsHeaders(): void
    {
        // Create the middleware with default settings
        $middleware = new CorsMiddleware();
        
        // Create a mock request with an origin
        $request = new Request();
        $request->headers->set('Origin', 'https://example.com');
        
        // Create a mock handler that returns a basic response
        $mockHandler = $this->createMockHandler();
        
        // Process the request through the middleware
        $response = $middleware->process($request, $mockHandler);
        
        // Verify CORS headers are set correctly
        $this->assertEquals('https://example.com', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('GET', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('POST', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('Authorization', $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertEquals('86400', $response->headers->get('Access-Control-Max-Age'));
    }
    
    /**
     * Test that OPTIONS preflight requests are handled properly
     */
    public function testHandlesPreflightRequests(): void
    {
        // Create the middleware
        $middleware = new CorsMiddleware();
        
        // Create a mock OPTIONS request
        $request = new Request();
        $request->setMethod('OPTIONS');
        $request->headers->set('Origin', 'https://example.com');
        
        // Create a mock handler (which should not be called for preflight)
        $mockHandler = $this->createMockHandler(false);
        
        // Process the preflight request
        $response = $middleware->process($request, $mockHandler);
        
        // Verify the response status code is 204 No Content
        $this->assertEquals(204, $response->getStatusCode());
        
        // Verify CORS headers are set
        $this->assertEquals('https://example.com', $response->headers->get('Access-Control-Allow-Origin'));
    }
    
    /**
     * Test that custom CORS configuration is respected
     */
    public function testCustomCorsConfiguration(): void
    {
        // Create the middleware with custom configuration
        $middleware = new CorsMiddleware([
            'allowedOrigins' => ['https://trusted-site.com'],
            'allowedMethods' => ['GET', 'POST'],
            'allowedHeaders' => ['Content-Type', 'X-Custom-Header'],
            'exposedHeaders' => ['X-Rate-Limit'],
            'maxAge' => 3600,
            'supportsCredentials' => true,
        ]);
        
        // Create a mock request with an allowed origin
        $request = new Request();
        $request->headers->set('Origin', 'https://trusted-site.com');
        
        // Create a mock handler
        $mockHandler = $this->createMockHandler();
        
        // Process the request
        $response = $middleware->process($request, $mockHandler);
        
        // Verify CORS headers reflect the custom configuration
        $this->assertEquals('https://trusted-site.com', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertEquals('GET, POST', $response->headers->get('Access-Control-Allow-Methods'));
        $this->assertEquals('Content-Type, X-Custom-Header', $response->headers->get('Access-Control-Allow-Headers'));
        $this->assertEquals('X-Rate-Limit', $response->headers->get('Access-Control-Expose-Headers'));
        $this->assertEquals('3600', $response->headers->get('Access-Control-Max-Age'));
        $this->assertEquals('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }
    
    /**
     * Test that unauthorized origins don't get CORS headers
     */
    public function testUnauthorizedOriginDoesNotGetCorsHeaders(): void
    {
        // Create the middleware with specific origins
        $middleware = new CorsMiddleware([
            'allowedOrigins' => ['https://trusted-site.com'],
        ]);
        
        // Create a mock request with an unauthorized origin
        $request = new Request();
        $request->headers->set('Origin', 'https://untrusted-site.com');
        
        // Create a mock handler
        $mockHandler = $this->createMockHandler();
        
        // Process the request
        $response = $middleware->process($request, $mockHandler);
        
        // Verify CORS headers are not set
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
    
    /**
     * Test that wildcard origin allows any origin
     */
    public function testWildcardOriginAllowsAnyOrigin(): void
    {
        // Create the middleware with wildcard origin
        $middleware = new CorsMiddleware([
            'allowedOrigins' => ['*'],
        ]);
        
        // Create a mock request with any origin
        $request = new Request();
        $request->headers->set('Origin', 'https://any-site.com');
        
        // Create a mock handler
        $mockHandler = $this->createMockHandler();
        
        // Process the request
        $response = $middleware->process($request, $mockHandler);
        
        // Verify the specific origin is reflected back
        $this->assertEquals('https://any-site.com', $response->headers->get('Access-Control-Allow-Origin'));
    }
    
    /**
     * Create a mock request handler for testing
     * 
     * @param bool $shouldBeCalled Whether the handler is expected to be called
     * @return RequestHandlerInterface&\PHPUnit\Framework\MockObject\MockObject A mock request handler
     */
    private function createMockHandler(bool $shouldBeCalled = true)
    {
        $mockHandler = $this->createMock(RequestHandlerInterface::class);
        
        if ($shouldBeCalled) {
            $mockHandler->expects($this->once())
                ->method('handle')
                ->willReturn(new Response());
        } else {
            $mockHandler->expects($this->never())
                ->method('handle');
        }
        
        return $mockHandler;
    }
}
