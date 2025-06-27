<?php

namespace Glueful\Http\Middleware;

use Glueful\Http\Middleware\MiddlewareInterface;
use Glueful\Http\Middleware\RequestHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Glueful\Http\Response;

/**
 * {{MIDDLEWARE_NAME}} Middleware
 *
 * {{MIDDLEWARE_DESCRIPTION}}
 *
 * @package Glueful\Http\Middleware
 */
class {{MIDDLEWARE_NAME}} implements MiddlewareInterface
{
    /**
     * Process an incoming server request
     *
     * @param Request $request
     * @param RequestHandlerInterface $handler
     * @return Response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Pre-processing logic here
        // This runs BEFORE the request is handled
        
        // Example: Check if user is authenticated
        // if (!$this->isAuthenticated($request)) {
        //     return Response::json([
        //         'error' => 'Unauthorized access'
        //     ], 401);
        // }

        // Example: Add custom headers
        // $request->headers->set('X-Custom-Header', 'value');

        // Example: Log the request
        // $this->logRequest($request);

        // Call the next middleware/handler
        $response = $handler->handle($request);

        // Post-processing logic here
        // This runs AFTER the request is handled

        // Example: Add response headers
        // $response->headers->set('X-Processed-By', '{{MIDDLEWARE_NAME}}');

        // Example: Log the response
        // $this->logResponse($response);

        return $response;
    }

    /**
     * Check if the request is authenticated
     *
     * @param Request $request
     * @return bool
     */
    private function isAuthenticated(Request $request): bool
    {
        // TODO: Implement authentication logic
        // Example:
        // $token = $request->header('Authorization');
        // return $this->validateToken($token);
        
        return true; // Placeholder - implement your logic
    }

    /**
     * Validate a token
     *
     * @param string|null $token
     * @return bool
     */
    private function validateToken(?string $token): bool
    {
        // TODO: Implement token validation logic
        if (empty($token)) {
            return false;
        }

        // Remove 'Bearer ' prefix if present
        if (strpos($token, 'Bearer ') === 0) {
            $token = substr($token, 7);
        }

        // Implement your token validation here
        // Example: JWT validation, database lookup, etc.
        
        return !empty($token); // Placeholder
    }

    /**
     * Log the incoming request
     *
     * @param Request $request
     * @return void
     */
    private function logRequest(Request $request): void
    {
        // TODO: Implement request logging
        // Example:
        // error_log(sprintf(
        //     '[%s] %s %s - User: %s',
        //     date('Y-m-d H:i:s'),
        //     $request->getMethod(),
        //     $request->getUri(),
        //     $request->user()->id ?? 'anonymous'
        // ));
    }

    /**
     * Log the outgoing response
     *
     * @param Response $response
     * @return void
     */
    private function logResponse(Response $response): void
    {
        // TODO: Implement response logging
        // Example:
        // error_log(sprintf(
        //     '[%s] Response: %d - Size: %d bytes',
        //     date('Y-m-d H:i:s'),
        //     $response->getStatusCode(),
        //     strlen($response->getContent())
        // ));
    }

    /**
     * Check if request has valid permissions
     *
     * @param Request $request
     * @param array $requiredPermissions
     * @return bool
     */
    private function hasPermissions(Request $request, array $requiredPermissions = []): bool
    {
        // TODO: Implement permission checking logic
        // Example:
        // $user = $request->user();
        // if (!$user) return false;
        // 
        // foreach ($requiredPermissions as $permission) {
        //     if (!$user->hasPermission($permission)) {
        //         return false;
        //     }
        // }
        
        return true; // Placeholder
    }

    /**
     * Apply rate limiting
     *
     * @param Request $request
     * @return bool True if request is allowed, false if rate limited
     */
    private function applyRateLimit(Request $request): bool
    {
        // TODO: Implement rate limiting logic
        // Example:
        // $clientId = $this->getClientIdentifier($request);
        // $rateLimiter = new RateLimiter();
        // return $rateLimiter->attempt($clientId, 60, 100); // 100 requests per 60 seconds
        
        return true; // Placeholder
    }

    /**
     * Get client identifier for rate limiting
     *
     * @param Request $request
     * @return string
     */
    private function getClientIdentifier(Request $request): string
    {
        // Use user ID if authenticated, otherwise IP address
        $user = $request->user();
        return $user ? 'user:' . $user->id : 'ip:' . $request->ip();
    }
}