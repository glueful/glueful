<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\AuthBootstrap;

/**
 * Authentication Middleware
 * 
 * Validates user authentication using the Authentication Manager.
 * This middleware implements the PSR-15 compatible interface and
 * leverages the abstracted authentication system for flexibility.
 */
class AuthenticationMiddleware implements MiddlewareInterface
{
    /** @var bool Whether to require admin privileges */
    private bool $requiresAdmin;
    
    /** @var AuthenticationManager Authentication manager instance */
    private AuthenticationManager $authManager;
    
    /** @var array Optional provider names to try */
    private array $providerNames = [];
    
    /**
     * Create a new authentication middleware
     * 
     * @param bool $requiresAdmin Whether to require admin privileges
     * @param AuthenticationManager|null $authManager Optional custom auth manager
     * @param array $providerNames Provider names to try in sequence
     */
    public function __construct(
        bool $requiresAdmin = false, 
        ?AuthenticationManager $authManager = null,
        array $providerNames = []
    ) {
        $this->requiresAdmin = $requiresAdmin;
        
        // Use provided AuthManager or get from bootstrap for global consistency
        $this->authManager = $authManager ?? AuthBootstrap::getManager();
        
        // Default to using JWT and API key auth if none specified
        $this->providerNames = !empty($providerNames) ? $providerNames : ['jwt', 'api_key'];
    }
    
    /**
     * Process the request through the authentication middleware
     * 
     * @param Request $request The incoming request
     * @param RequestHandlerInterface $handler The next handler in the pipeline
     * @return Response The response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Try to authenticate the request
        $userData = $this->authenticate($request);
        
        if (!$userData) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->authManager->getError() ?? 'Unauthorized access, invalid or expired token',
                'code' => 401
            ], 401);
        }
        
        // Attach user data to request attributes for controllers to access
        $request->attributes->set('user', $userData);
        
        // Check admin permissions if required
        if ($this->requiresAdmin && !$this->authManager->isAdmin($userData)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Insufficient permissions, admin access required',
                'code' => 403
            ], 403);
        }
        
        // Log successful authentication if logging is enabled
        if (method_exists($this->authManager, 'logAccess')) {
            $this->authManager->logAccess($userData, $request);
        }
        
        // Authentication passed, continue to next middleware
        return $handler->handle($request);
    }
    
    /**
     * Authenticate the request with appropriate providers
     * 
     * @param Request $request The HTTP request
     * @return array|null User data if authenticated, null otherwise
     */
    private function authenticate(Request $request): ?array
    {
        // If specific providers are requested, try them in sequence
        if (!empty($this->providerNames)) {
            return $this->authManager->authenticateWithProviders($this->providerNames, $request);
        }
        
        // Otherwise use the default provider
        return $this->authManager->authenticate($request);
    }
}