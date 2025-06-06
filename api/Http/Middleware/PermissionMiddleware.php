<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Glueful\Permissions\PermissionManager;
use Glueful\Permissions\Exceptions\PermissionException;
use Glueful\Permissions\Exceptions\ProviderNotFoundException;
use Glueful\Interfaces\Permission\PermissionStandards;
use Glueful\Auth\AuthenticationService;
use Glueful\Auth\TokenManager;
use Glueful\Exceptions\SecurityException;
use Glueful\Exceptions\AuthenticationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Permission Middleware
 *
 * Generic middleware for checking user permissions on protected routes.
 * This middleware integrates with the permission system to enforce
 * access control based on user permissions.
 *
 * Usage:
 * - Apply to routes that require specific permissions
 * - Configure with permission and resource requirements
 * - Supports both session and token-based authentication
 *
 * @package Glueful\Http\Middleware
 */
class PermissionMiddleware implements MiddlewareInterface
{
    /** @var string Required permission for access */
    private string $permission;

    /** @var string Resource being accessed */
    private string $resource;

    /** @var array Additional context for permission check */
    private array $context;

    /** @var bool Whether to require authentication */
    private bool $requireAuth;

    /** @var PermissionManager Permission manager instance */
    private PermissionManager $permissionManager;

    /**
     * Constructor
     *
     * @param string $permission Required permission (e.g., 'view', 'edit', 'delete')
     * @param string $resource Resource identifier (e.g., 'users', 'posts', 'settings')
     * @param array $context Additional context for permission checking
     * @param bool $requireAuth Whether authentication is required
     */
    public function __construct(
        string $permission,
        string $resource,
        array $context = [],
        bool $requireAuth = true
    ) {
        $this->permission = $permission;
        $this->resource = $resource;
        $this->context = $context;
        $this->requireAuth = $requireAuth;
        $this->permissionManager = new PermissionManager();
    }

    /**
     * Process the request
     *
     * @param Request $request The incoming request
     * @param RequestHandlerInterface $handler The request handler
     * @return Response The response
     */
    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        try {
            // Get user identification
            $userUuid = $this->getUserUuid($request);

            // If authentication is required but no user found
            if ($this->requireAuth && !$userUuid) {
                return $this->createErrorResponse(
                    'Authentication required',
                    401,
                    'AUTHENTICATION_REQUIRED'
                );
            }

            // If we have a user, check permissions
            if ($userUuid) {
                // Check if permission system is available
                if (!$this->permissionManager->isAvailable()) {
                    // Fallback: Allow any authenticated user when permission system unavailable
                    error_log("FALLBACK: Permission system unavailable in middleware, " .
                              "allowing authenticated access for: {$this->permission} on {$this->resource}");

                    // Add user context to request for downstream middleware/controllers
                    $request->attributes->set('user_uuid', $userUuid);
                    $request->attributes->set('permission_context', $this->context);

                    // Continue to next middleware/handler
                    return $handler->handle($request);
                }

                $hasPermission = $this->checkPermission($userUuid, $request);

                if (!$hasPermission) {
                    return $this->createErrorResponse(
                        'Insufficient permissions',
                        403,
                        'INSUFFICIENT_PERMISSIONS',
                        [
                            'required_permission' => $this->permission,
                            'resource' => $this->resource
                        ]
                    );
                }

                // Add user context to request for downstream middleware/controllers
                $request->attributes->set('user_uuid', $userUuid);
                $request->attributes->set('permission_context', $this->context);
            }

            // Permission check passed, continue to next middleware/handler
            return $handler->handle($request);
        } catch (ProviderNotFoundException $e) {
            // If provider not found, apply fallback logic
            $userUuid = $this->getUserUuid($request);

            if ($this->requireAuth && !$userUuid) {
                return $this->createErrorResponse(
                    'Authentication required',
                    401,
                    'AUTHENTICATION_REQUIRED'
                );
            }

            if ($userUuid) {
                // Fallback: Allow authenticated user when provider not found
                error_log("FALLBACK: Provider not found exception, allowing authenticated access for: " .
                          "{$this->permission}");
                $request->attributes->set('user_uuid', $userUuid);
                $request->attributes->set('permission_context', $this->context);
                return $handler->handle($request);
            }

            return $this->createErrorResponse(
                'Permission provider not configured',
                503,
                'PERMISSION_PROVIDER_NOT_FOUND'
            );
        } catch (PermissionException $e) {
            return $this->createErrorResponse(
                'Permission check failed: ' . $e->getMessage(),
                500,
                'PERMISSION_CHECK_FAILED'
            );
        } catch (AuthenticationException $e) {
            return $this->createErrorResponse(
                'Authentication failed: ' . $e->getMessage(),
                401,
                'AUTHENTICATION_FAILED'
            );
        } catch (SecurityException $e) {
            return $this->createErrorResponse(
                'Security violation: ' . $e->getMessage(),
                403,
                'SECURITY_VIOLATION'
            );
        } catch (\Exception $e) {
            error_log("Permission middleware error: " . $e->getMessage());
            return $this->createErrorResponse(
                'Internal server error',
                500,
                'INTERNAL_ERROR'
            );
        }
    }

    /**
     * Extract user UUID from request
     *
     * @param Request $request The request
     * @return string|null User UUID or null if not found
     */
    private function getUserUuid(Request $request): ?string
    {
        // Try to get user from session first
        $sessionUser = $request->getSession()->get('user');
        if ($sessionUser && isset($sessionUser['uuid'])) {
            return $sessionUser['uuid'];
        }

        // Try to get user from Authorization header (Bearer token)
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            return $this->getUserUuidFromToken($token);
        }

        // Try to get user from custom auth token header
        $tokenHeader = $request->headers->get('X-Auth-Token');
        if ($tokenHeader) {
            return $this->getUserUuidFromToken($tokenHeader);
        }

        // Try to get user from query parameter (for API compatibility)
        $queryToken = $request->query->get('auth_token');
        if ($queryToken) {
            return $this->getUserUuidFromToken($queryToken);
        }

        return null;
    }

    /**
     * Extract user UUID from authentication token
     *
     * @param string $token The authentication token
     * @return string|null User UUID or null if extraction fails
     */
    private function getUserUuidFromToken(string $token): ?string
    {
        try {
            // Try to get session ID from token
            $sessionId = TokenManager::getSessionIdFromToken($token);
            if ($sessionId) {
                $session = \Glueful\Auth\SessionCacheManager::getSession($sessionId);
                if ($session && isset($session['user']['uuid'])) {
                    return $session['user']['uuid'];
                }
            }

            // Fallback: try direct token validation
            $user = AuthenticationService::validateAccessToken($token);
            if ($user && isset($user['uuid'])) {
                return $user['uuid'];
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check user permission
     *
     * @param string $userUuid User UUID
     * @param Request $request The request
     * @return bool True if user has permission
     */
    private function checkPermission(string $userUuid, Request $request): bool
    {
        // Build context with request information
        $context = array_merge($this->context, [
            'request_method' => $request->getMethod(),
            'request_path' => $request->getPathInfo(),
            'request_ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'timestamp' => time()
        ]);

        // Add route parameters if available
        if ($request->attributes->has('_route_params')) {
            $context['route_params'] = $request->attributes->get('_route_params');
        }

        // Add resource ID if present in route
        if ($request->attributes->has('id')) {
            $context['resource_id'] = $request->attributes->get('id');
        }

        // Perform permission check
        return $this->permissionManager->can(
            $userUuid,
            $this->permission,
            $this->resource,
            $context
        );
    }

    /**
     * Create error response
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param string $errorCode Application error code
     * @param array $details Additional error details
     * @return JsonResponse Error response
     */
    private function createErrorResponse(
        string $message,
        int $statusCode,
        string $errorCode,
        array $details = []
    ): JsonResponse {
        $error = [
            'error' => [
                'message' => $message,
                'code' => $errorCode,
                'status' => $statusCode
            ]
        ];

        if (!empty($details)) {
            $error['error']['details'] = $details;
        }

        return new JsonResponse($error, $statusCode);
    }

    /**
     * Create middleware instance for specific permission
     *
     * Factory method for easy middleware creation.
     *
     * @param string $permission Required permission
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @param bool $requireAuth Whether authentication is required
     * @return self Middleware instance
     */
    public static function require(
        string $permission,
        string $resource,
        array $context = [],
        bool $requireAuth = true
    ): self {
        return new self($permission, $resource, $context, $requireAuth);
    }

    /**
     * Create middleware for read/view access
     *
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return self Middleware instance
     */
    public static function read(string $resource, array $context = []): self
    {
        return new self(PermissionStandards::ACTION_VIEW, $resource, $context);
    }

    /**
     * Create middleware for write access
     *
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return self Middleware instance
     */
    public static function write(string $resource, array $context = []): self
    {
        return new self(PermissionStandards::ACTION_EDIT, $resource, $context);
    }

    /**
     * Create middleware for delete access
     *
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return self Middleware instance
     */
    public static function delete(string $resource, array $context = []): self
    {
        return new self(PermissionStandards::ACTION_DELETE, $resource, $context);
    }

    /**
     * Create middleware for admin access
     *
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return self Middleware instance
     */
    public static function admin(string $resource, array $context = []): self
    {
        return new self(PermissionStandards::PERMISSION_SYSTEM_ACCESS, $resource, $context);
    }

    /**
     * Create middleware for user management access
     *
     * @param string $action User action (view, create, edit, delete)
     * @param array $context Additional context
     * @return self Middleware instance
     */
    public static function users(string $action = PermissionStandards::ACTION_VIEW, array $context = []): self
    {
        $permission = match ($action) {
            PermissionStandards::ACTION_VIEW => PermissionStandards::PERMISSION_USERS_VIEW,
            PermissionStandards::ACTION_CREATE => PermissionStandards::PERMISSION_USERS_CREATE,
            PermissionStandards::ACTION_EDIT => PermissionStandards::PERMISSION_USERS_EDIT,
            PermissionStandards::ACTION_DELETE => PermissionStandards::PERMISSION_USERS_DELETE,
            default => PermissionStandards::PERMISSION_USERS_VIEW
        };

        return new self($permission, PermissionStandards::CATEGORY_USERS, $context);
    }
}
