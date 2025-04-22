<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Glueful\Repository\RoleRepository;
use Glueful\Auth\SessionCacheManager;
use Glueful\Auth\AuthenticationService;
use Glueful\Permissions\PermissionManager;

/**
 * Permission Middleware
 * 
 * PSR-15 compatible middleware that handles permission checking for routes.
 * Validates that the authenticated user has the required permission
 * for the requested resource before allowing the request to proceed.
 * 
 * Features:
 * - Flexible permission checking by model and action
 * - Debug mode for detailed permission diagnostics
 * - Automatic bypass for superuser roles
 * - Integration with the centralized PermissionManager
 */
class PermissionMiddleware implements MiddlewareInterface
{
    /** @var string Model/resource name to check permissions for */
    private string $model;
    
    /** @var string Permission (action) to check */
    private string $permission;
    
    /** @var bool Whether to enable debug mode for permission checks */
    private bool $debugMode;
    
    /**
     * Create a new permission middleware
     * 
     * @param string $model The resource model to check permissions for
     * @param string $permission The permission required (use Permission constants)
     * @param bool $debugMode Enable detailed debug information for permission checks
     */
    public function __construct(
        string $model,
        string $permission, 
        bool $debugMode = false
    ) {
        $this->model = $model;
        $this->permission = $permission;
        $this->debugMode = $debugMode;
        
        // Initialize PermissionManager
        PermissionManager::initialize();
    }
    
    /**
     * Process the request through the permission middleware
     * 
     * @param Request $request The incoming request
     * @param RequestHandlerInterface $handler The next handler in the pipeline
     * @return Response The response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        // Extract token from request
        $token = AuthenticationService::extractTokenFromRequest($request);
        
        if (!$token) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Unauthorized - no token provided',
                'code' => 401
            ], 401);
        }
        
        // Get session data
        $session = SessionCacheManager::getSession($token);
        
        if (!$session || !isset($session['user']['uuid'])) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Unauthorized - invalid session',
                'code' => 401
            ], 401);
        }
        
        $userUuid = $session['user']['uuid'];
        
        // Check if user has superuser role (bypass permission check)
        $roleRepo = new RoleRepository();
        if ($roleRepo->hasRole($userUuid, 'superuser')) {
            // Superuser has access to everything - proceed with the request
            return $handler->handle($request);
        }
        
        // For non-superusers, check permission using PermissionManager
        if ($this->debugMode) {
            // In debug mode, get detailed permission information
            $permDebug = PermissionManager::debug($this->model, $this->permission, $token);
            
            if (!$permDebug['has_permission']) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Forbidden - ' . $permDebug['reason'],
                    'code' => 403,
                    'debug' => $permDebug
                ], 403);
            }
        } else {
            // Standard permission check
            if (!PermissionManager::can($this->model, $this->permission, $token)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Forbidden - insufficient permissions',
                    'code' => 403
                ], 403);
            }
        }
        
        // If we get here, the user has the required permission
        return $handler->handle($request);
    }
}