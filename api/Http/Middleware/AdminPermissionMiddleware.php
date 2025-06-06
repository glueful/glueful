<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Glueful\Permissions\PermissionManager;
use Glueful\Permissions\Exceptions\PermissionException;
use Glueful\Permissions\Exceptions\ProviderNotFoundException;
use Glueful\Auth\AuthenticationService;
use Glueful\Auth\TokenManager;
use Glueful\Repository\UserRepository;
use Glueful\Exceptions\SecurityException;
use Glueful\Exceptions\AuthenticationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Admin Permission Middleware
 *
 * Specialized middleware for admin-only routes and operations.
 * This middleware provides enhanced security checks specifically
 * designed for administrative functionality.
 *
 * Features:
 * - Admin role verification
 * - Enhanced security logging
 * - IP whitelist support
 * - Session validation
 * - Elevated permission requirements
 *
 * @package Glueful\Http\Middleware
 */
class AdminPermissionMiddleware implements MiddlewareInterface
{
    /** @var string Admin-specific permission required */
    private string $adminPermission;

    /** @var string Resource being accessed */
    private string $resource;

    /** @var array Additional context for permission check */
    private array $context;

    /** @var array Allowed IP addresses for admin access */
    private array $allowedIps;

    /** @var bool Whether to require elevated authentication */
    private bool $requireElevated;

    /** @var PermissionManager Permission manager instance */
    private PermissionManager $permissionManager;

    /** @var UserRepository User repository for admin checks */
    private UserRepository $userRepository;

    /**
     * Constructor
     *
     * @param string $adminPermission Admin permission required (e.g., 'admin', 'superadmin')
     * @param string $resource Resource identifier (e.g., 'users', 'system', 'settings')
     * @param array $context Additional context for permission checking
     * @param array $allowedIps Allowed IP addresses (empty = allow all)
     * @param bool $requireElevated Whether to require elevated authentication
     */
    public function __construct(
        string $adminPermission = 'admin',
        string $resource = 'system',
        array $context = [],
        array $allowedIps = [],
        bool $requireElevated = true
    ) {
        $this->adminPermission = $adminPermission;
        $this->resource = $resource;
        $this->context = $context;
        $this->allowedIps = $allowedIps;
        $this->requireElevated = $requireElevated;
        $this->permissionManager = new PermissionManager();
        $this->userRepository = new UserRepository();
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
            // Check if permission system is available
            if (!$this->permissionManager->isAvailable()) {
                $this->logSecurityEvent($request, 'permission_system_unavailable', null);
                return $this->createAdminErrorResponse(
                    'Admin permission system not available',
                    503,
                    'ADMIN_PERMISSION_SYSTEM_UNAVAILABLE'
                );
            }

            // Check IP whitelist if configured
            if (!$this->checkIpAccess($request)) {
                $this->logSecurityEvent($request, 'ip_access_denied', null);
                return $this->createAdminErrorResponse(
                    'Access denied from this IP address',
                    403,
                    'IP_ACCESS_DENIED'
                );
            }

            // Get and validate user
            $userUuid = $this->getUserUuid($request);
            if (!$userUuid) {
                $this->logSecurityEvent($request, 'admin_authentication_required', null);
                return $this->createAdminErrorResponse(
                    'Admin authentication required',
                    401,
                    'ADMIN_AUTHENTICATION_REQUIRED'
                );
            }

            // Validate user account status
            if (!$this->validateUserStatus($userUuid)) {
                $this->logSecurityEvent($request, 'admin_account_invalid', $userUuid);
                return $this->createAdminErrorResponse(
                    'Admin account not valid',
                    403,
                    'ADMIN_ACCOUNT_INVALID'
                );
            }

            // Check elevated authentication if required
            if ($this->requireElevated && !$this->checkElevatedAuth($request, $userUuid)) {
                $this->logSecurityEvent($request, 'elevated_auth_required', $userUuid);
                return $this->createAdminErrorResponse(
                    'Elevated authentication required for admin access',
                    403,
                    'ELEVATED_AUTH_REQUIRED'
                );
            }

            // Check admin permissions
            if (!$this->checkAdminPermission($userUuid, $request)) {
                $this->logSecurityEvent($request, 'admin_permission_denied', $userUuid);
                return $this->createAdminErrorResponse(
                    'Insufficient admin permissions',
                    403,
                    'INSUFFICIENT_ADMIN_PERMISSIONS',
                    [
                        'required_permission' => $this->adminPermission,
                        'resource' => $this->resource
                    ]
                );
            }

            // Log successful admin access
            $this->logSecurityEvent($request, 'admin_access_granted', $userUuid);

            // Add admin context to request
            $request->attributes->set('admin_user_uuid', $userUuid);
            $request->attributes->set('admin_permission', $this->adminPermission);
            $request->attributes->set('admin_context', $this->context);
            $request->attributes->set('is_admin_request', true);

            // Admin permission check passed, continue
            return $handler->handle($request);
        } catch (ProviderNotFoundException $e) {
            $this->logSecurityEvent($request, 'admin_provider_not_found', $userUuid ?? null);
            return $this->createAdminErrorResponse(
                'Admin permission provider not configured',
                503,
                'ADMIN_PERMISSION_PROVIDER_NOT_FOUND'
            );
        } catch (PermissionException $e) {
            $this->logSecurityEvent($request, 'admin_permission_error', $userUuid ?? null, $e->getMessage());
            return $this->createAdminErrorResponse(
                'Admin permission check failed',
                500,
                'ADMIN_PERMISSION_CHECK_FAILED'
            );
        } catch (AuthenticationException $e) {
            $this->logSecurityEvent($request, 'admin_authentication_failed', $userUuid ?? null, $e->getMessage());
            return $this->createAdminErrorResponse(
                'Admin authentication failed',
                401,
                'ADMIN_AUTHENTICATION_FAILED'
            );
        } catch (SecurityException $e) {
            $this->logSecurityEvent($request, 'admin_security_violation', $userUuid ?? null, $e->getMessage());
            return $this->createAdminErrorResponse(
                'Admin security violation',
                403,
                'ADMIN_SECURITY_VIOLATION'
            );
        } catch (\Exception $e) {
            $this->logSecurityEvent($request, 'admin_middleware_error', $userUuid ?? null, $e->getMessage());
            return $this->createAdminErrorResponse(
                'Internal admin system error',
                500,
                'ADMIN_INTERNAL_ERROR'
            );
        }
    }

    /**
     * Check IP access against whitelist
     *
     * @param Request $request The request
     * @return bool True if IP is allowed
     */
    private function checkIpAccess(Request $request): bool
    {
        // If no IP restrictions configured, allow all
        if (empty($this->allowedIps)) {
            return true;
        }

        $clientIp = $request->getClientIp();
        if (!$clientIp) {
            return false;
        }

        // Check if client IP is in allowed list
        foreach ($this->allowedIps as $allowedIp) {
            if ($this->ipMatches($clientIp, $allowedIp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP matches pattern
     *
     * @param string $clientIp Client IP address
     * @param string $pattern IP pattern (supports CIDR notation)
     * @return bool True if IP matches
     */
    private function ipMatches(string $clientIp, string $pattern): bool
    {
        // Exact match
        if ($clientIp === $pattern) {
            return true;
        }

        // CIDR notation support
        if (str_contains($pattern, '/')) {
            [$network, $bits] = explode('/', $pattern);
            $clientLong = ip2long($clientIp);
            $networkLong = ip2long($network);
            $mask = -1 << (32 - (int)$bits);
            return ($clientLong & $mask) === ($networkLong & $mask);
        }

        return false;
    }

    /**
     * Extract user UUID from request
     *
     * @param Request $request The request
     * @return string|null User UUID or null if not found
     */
    private function getUserUuid(Request $request): ?string
    {
        // Try session first (preferred for admin)
        $sessionUser = $request->getSession()->get('user');
        if ($sessionUser && isset($sessionUser['uuid'])) {
            return $sessionUser['uuid'];
        }

        // Try Authorization header
        $authHeader = $request->headers->get('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $token = substr($authHeader, 7);
            return $this->getUserUuidFromToken($token);
        }

        // Try custom auth token header
        $tokenHeader = $request->headers->get('X-Admin-Token');
        if ($tokenHeader) {
            return $this->getUserUuidFromToken($tokenHeader);
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
            $sessionId = TokenManager::getSessionIdFromToken($token);
            if ($sessionId) {
                $session = \Glueful\Auth\SessionCacheManager::getSession($sessionId);
                if ($session && isset($session['user']['uuid'])) {
                    return $session['user']['uuid'];
                }
            }

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
     * Validate user account status for admin access
     *
     * @param string $userUuid User UUID
     * @return bool True if user account is valid for admin access
     */
    private function validateUserStatus(string $userUuid): bool
    {
        try {
            $user = $this->userRepository->findByUuid($userUuid);
            if (!$user) {
                return false;
            }

            // Check if user is active
            if (!isset($user['is_active']) || !$user['is_active']) {
                return false;
            }

            // Check if user is an admin
            if (!isset($user['is_admin']) || !$user['is_admin']) {
                return false;
            }

            // Additional admin-specific checks can be added here
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check elevated authentication requirements
     *
     * @param Request $request The request
     * @param string $userUuid User UUID
     * @return bool True if elevated authentication is satisfied
     */
    private function checkElevatedAuth(Request $request, string $userUuid): bool
    {
        // Check for recent authentication (within last 15 minutes for admin actions)
        $session = $request->getSession();
        $lastAuth = $session->get('last_admin_auth');
        if ($lastAuth && (time() - $lastAuth) < 900) { // 15 minutes
            return true;
        }

        // Check for elevated auth header
        $elevatedHeader = $request->headers->get('X-Elevated-Auth');
        if ($elevatedHeader) {
            // Validate elevated auth token/signature
            return $this->validateElevatedAuth($elevatedHeader, $userUuid);
        }

        return false;
    }

    /**
     * Validate elevated authentication token
     *
     * @param string $elevatedAuth Elevated auth token/signature
     * @param string $userUuid User UUID
     * @return bool True if elevated auth is valid
     */
    private function validateElevatedAuth(string $elevatedAuth, string $userUuid): bool
    {
        // Implementation would depend on your elevated auth strategy
        // This could be a time-limited token, TOTP, or other mechanism
        return false; // Placeholder - implement based on your requirements
    }

    /**
     * Check admin permission
     *
     * @param string $userUuid User UUID
     * @param Request $request The request
     * @return bool True if user has admin permission
     */
    private function checkAdminPermission(string $userUuid, Request $request): bool
    {
        $context = array_merge($this->context, [
            'admin_request' => true,
            'request_method' => $request->getMethod(),
            'request_path' => $request->getPathInfo(),
            'request_ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'timestamp' => time(),
            'security_level' => 'admin'
        ]);

        if ($request->attributes->has('_route_params')) {
            $context['route_params'] = $request->attributes->get('_route_params');
        }

        return $this->permissionManager->can(
            $userUuid,
            $this->adminPermission,
            $this->resource,
            $context
        );
    }

    /**
     * Log security event for admin access
     *
     * @param Request $request The request
     * @param string $event Event type
     * @param string|null $userUuid User UUID if available
     * @param string|null $details Additional details
     * @return void
     */
    private function logSecurityEvent(
        Request $request,
        string $event,
        ?string $userUuid,
        ?string $details = null
    ): void {
        $logData = [
            'event' => $event,
            'admin_middleware' => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'permission' => $this->adminPermission,
            'resource' => $this->resource
        ];

        if ($userUuid) {
            $logData['user_uuid'] = $userUuid;
        }

        if ($details) {
            $logData['details'] = $details;
        }

        // Log to security audit log
        error_log('[ADMIN_SECURITY] ' . json_encode($logData));
    }

    /**
     * Create admin-specific error response
     *
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @param string $errorCode Application error code
     * @param array $details Additional error details
     * @return JsonResponse Error response
     */
    private function createAdminErrorResponse(
        string $message,
        int $statusCode,
        string $errorCode,
        array $details = []
    ): JsonResponse {
        $error = [
            'error' => [
                'message' => $message,
                'code' => $errorCode,
                'status' => $statusCode,
                'type' => 'admin_access_error'
            ]
        ];

        if (!empty($details)) {
            $error['error']['details'] = $details;
        }

        return new JsonResponse($error, $statusCode);
    }

    /**
     * Create middleware for superuser access
     *
     * @param string $resource Resource identifier
     * @param array $allowedIps Allowed IP addresses
     * @return self Middleware instance
     */
    public static function superuser(string $resource = 'system', array $allowedIps = []): self
    {
        return new self('superuser', $resource, ['level' => 'super'], $allowedIps, true);
    }

    /**
     * Create middleware for system admin access
     *
     * @param string $resource Resource identifier
     * @param array $allowedIps Allowed IP addresses
     * @return self Middleware instance
     */
    public static function systemAdmin(string $resource = 'system', array $allowedIps = []): self
    {
        return new self('system_admin', $resource, ['level' => 'system'], $allowedIps, true);
    }

    /**
     * Create middleware for user admin access
     *
     * @param array $allowedIps Allowed IP addresses
     * @return self Middleware instance
     */
    public static function userAdmin(array $allowedIps = []): self
    {
        return new self('user_admin', 'users', ['level' => 'user_management'], $allowedIps, false);
    }

    /**
     * Create middleware for content admin access
     *
     * @param array $allowedIps Allowed IP addresses
     * @return self Middleware instance
     */
    public static function contentAdmin(array $allowedIps = []): self
    {
        return new self('content_admin', 'content', ['level' => 'content_management'], $allowedIps, false);
    }
}
