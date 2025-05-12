<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS Middleware
 *
 * Handles Cross-Origin Resource Sharing (CORS) headers.
 * This middleware implements the PSR-15 compatible interface.
 */
class CorsMiddleware implements MiddlewareInterface
{
    /** @var array CORS configuration settings */
    private array $config;

    /**
     * Create a new CORS middleware
     *
     * @param array $config CORS configuration settings
     */
    public function __construct(array $config = [])
    {
        // Default CORS configuration
        $this->config = array_merge([
            'allowedOrigins' => ['*'],
            'allowedMethods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowedHeaders' => ['Content-Type', 'Authorization', 'X-Requested-With'],
            'exposedHeaders' => [],
            'maxAge' => 86400, // 24 hours
            'supportsCredentials' => false,
        ], $config);
    }

    /**
     * Process the request through the CORS middleware
     *
     * @param Request $request The incoming request
     * @param RequestHandlerInterface $handler The next handler in the pipeline
     * @return Response The response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response();
            $response->setStatusCode(204); // No Content
        } else {
            // Process the request through the middleware pipeline
            $response = $handler->handle($request);
        }

        // Add CORS headers to the response
        $response = $this->addCorsHeaders($request, $response);

        return $response;
    }

    /**
     * Add CORS headers to the response
     *
     * @param Request $request The incoming request
     * @param Response $response The response
     * @return Response The modified response
     */
    private function addCorsHeaders(Request $request, Response $response): Response
    {
        $origin = $request->headers->get('Origin');

        // Check if the origin is allowed
        if ($origin && $this->isAllowedOrigin($origin)) {
            // Set the Access-Control-Allow-Origin header
            $response->headers->set('Access-Control-Allow-Origin', $origin);

            // Set other CORS headers
            $response->headers->set('Access-Control-Allow-Methods', implode(', ', $this->config['allowedMethods']));
            $response->headers->set('Access-Control-Allow-Headers', implode(', ', $this->config['allowedHeaders']));

            // Add optional CORS headers
            if (!empty($this->config['exposedHeaders'])) {
                $exposedHeadersValue = implode(', ', $this->config['exposedHeaders']);
                $response->headers->set('Access-Control-Expose-Headers', $exposedHeadersValue);
            }

            if ($this->config['maxAge']) {
                $response->headers->set('Access-Control-Max-Age', (string) $this->config['maxAge']);
            }

            if ($this->config['supportsCredentials']) {
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
            }
        }

        return $response;
    }

    /**
     * Check if the origin is allowed
     *
     * @param string $origin The origin to check
     * @return bool Whether the origin is allowed
     */
    private function isAllowedOrigin(string $origin): bool
    {
        // Allow all origins
        if (in_array('*', $this->config['allowedOrigins'])) {
            return true;
        }

        // Check if the origin is in the allowed origins list
        return in_array($origin, $this->config['allowedOrigins']);
    }
}
