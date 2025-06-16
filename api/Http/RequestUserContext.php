<?php

declare(strict_types=1);

namespace Glueful\Http;

use Glueful\Auth\TokenManager;
use Glueful\Auth\SessionCacheManager;
use Glueful\Models\User;

/**
 * Request User Context
 *
 * Provides request-level caching of user authentication data to avoid
 * repeated database queries and token validation during a single request.
 *
 * Features:
 * - Single authentication check per request
 * - Cached session data
 * - Lazy loading of user information
 * - Permission result caching
 * - Request-scoped singleton pattern
 *
 * @package Glueful\Http
 */
class RequestUserContext
{
    /** @var array<string, self> Request-scoped instances */
    private static array $instances = [];

    /** @var string|null Cached authentication token */
    private ?string $token = null;

    /** @var array|null Cached session data */
    private ?array $sessionData = null;

    /** @var User|null Cached user object */
    private ?User $user = null;

    /** @var array<string, bool> Cached permission results */
    private array $permissionCache = [];

    /** @var array<string, mixed> Cached user roles and capabilities */
    private array $userCapabilities = [];

    /** @var bool Whether authentication has been attempted */
    private bool $authAttempted = false;

    /** @var bool Whether user is authenticated */
    private bool $isAuthenticated = false;

    /** @var array<string, mixed> Request metadata */
    private array $requestMetadata = [];

    /** @var string Request ID for tracking */
    private string $requestId;

    /**
     * Private constructor to enforce singleton pattern
     */
    private function __construct(string $requestId)
    {
        $this->requestId = $requestId;
        $this->requestMetadata = [
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'timestamp' => time()
        ];
    }

    /**
     * Get request-scoped instance
     *
     * @param string|null $requestId Optional request ID for tracking
     * @return self Request user context instance
     */
    public static function getInstance(?string $requestId = null): self
    {
        $requestId = $requestId ?? self::generateRequestId();

        if (!isset(self::$instances[$requestId])) {
            self::$instances[$requestId] = new self($requestId);
        }

        return self::$instances[$requestId];
    }

    /**
     * Initialize user context with authentication check
     *
     * @return self Fluent interface
     */
    public function initialize(): self
    {
        if ($this->authAttempted) {
            return $this;
        }

        $this->authAttempted = true;

        try {
            // Extract token once per request
            $this->token = TokenManager::extractTokenFromRequest();

            if ($this->token) {
                // Get optimized session data with context-aware caching
                $context = [
                    'request_id' => $this->requestId,
                    'ip_address' => $this->requestMetadata['ip_address'],
                    'user_agent' => $this->requestMetadata['user_agent']
                ];
                $this->sessionData = SessionCacheManager::getOptimizedSession($this->token, $context);

                if ($this->sessionData && isset($this->sessionData['user'])) {
                    $this->user = User::fromArray($this->sessionData['user']);
                    $this->isAuthenticated = true;

                    // Pre-cache user capabilities with enhanced permission data
                    $this->loadUserCapabilities();
                }
            }
        } catch (\Exception $e) {
            // Log authentication error but don't throw
            error_log("RequestUserContext initialization failed: " . $e->getMessage());
            $this->isAuthenticated = false;
        }

        return $this;
    }

    /**
     * Get authenticated user
     *
     * @return User|null Authenticated user or null
     */
    public function getUser(): ?User
    {
        $this->initialize();
        return $this->user;
    }

    /**
     * Get user UUID
     *
     * @return string|null User UUID or null
     */
    public function getUserUuid(): ?string
    {
        return $this->getUser()?->uuid;
    }

    /**
     * Get authentication token
     *
     * @return string|null Authentication token or null
     */
    public function getToken(): ?string
    {
        $this->initialize();
        return $this->token;
    }

    /**
     * Get session data
     *
     * @return array|null Session data or null
     */
    public function getSessionData(): ?array
    {
        $this->initialize();
        return $this->sessionData;
    }

    /**
     * Check if user is authenticated
     *
     * @return bool True if authenticated
     */
    public function isAuthenticated(): bool
    {
        $this->initialize();
        return $this->isAuthenticated;
    }

    /**
     * Check if user is admin (cached)
     *
     * Uses AuthenticationManager's permission-based admin check for proper validation
     *
     * @return bool True if user is admin
     */
    public function isAdmin(): bool
    {
        $cacheKey = 'is_admin';

        if (!isset($this->permissionCache[$cacheKey])) {
            $user = $this->getUser();
            if (!$user) {
                $this->permissionCache[$cacheKey] = false;
            } else {
                // Use AuthenticationManager's proper admin check
                $userData = $user->toArray();

                // Get AuthenticationManager instance
                $authManager = \Glueful\Auth\AuthBootstrap::getManager();
                $this->permissionCache[$cacheKey] = $authManager->isAdmin($userData);
            }
        }

        return $this->permissionCache[$cacheKey];
    }

    /**
     * Check permission with caching
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return bool True if user has permission
     */
    public function hasPermission(string $permission, string $resource = 'system', array $context = []): bool
    {
        $cacheKey = sprintf('permission:%s:%s:%s', $permission, $resource, md5(serialize($context)));

        if (!isset($this->permissionCache[$cacheKey])) {
            $user = $this->getUser();

            if (!$user) {
                $this->permissionCache[$cacheKey] = false;
            } elseif ($this->isAdmin()) {
                $this->permissionCache[$cacheKey] = true;
            } else {
                // Use session-cached permissions for fast checking
                $this->permissionCache[$cacheKey] = $this->checkSessionPermission($permission, $resource, $context);
            }
        }

        return $this->permissionCache[$cacheKey];
    }

    /**
     * Get user roles (cached)
     *
     * @return array User roles
     */
    public function getUserRoles(): array
    {
        $cacheKey = 'user_roles';

        if (!isset($this->userCapabilities[$cacheKey])) {
            $user = $this->getUser();

            if (!$user) {
                $this->userCapabilities[$cacheKey] = [];
            } else {
                // Load roles from session data (nested in user object)
                $this->userCapabilities[$cacheKey] = $this->sessionData['user']['roles'] ?? [];
            }
        }

        return $this->userCapabilities[$cacheKey];
    }

    /**
     * Get user permissions (cached)
     *
     * @return array User permissions
     */
    public function getUserPermissions(): array
    {
        $cacheKey = 'user_permissions';

        if (!isset($this->userCapabilities[$cacheKey])) {
            $user = $this->getUser();

            if (!$user) {
                $this->userCapabilities[$cacheKey] = [];
            } else {
                // Load permissions from session data (nested in user object)
                $this->userCapabilities[$cacheKey] = $this->sessionData['user']['permissions'] ?? [];
            }
        }

        return $this->userCapabilities[$cacheKey];
    }

    /**
     * Check permission using session-cached data with fallback
     *
     * Fast permission checking using pre-loaded session permissions with
     * fallback to PermissionHelper for authoritative validation.
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return bool True if user has permission
     */
    private function checkSessionPermission(string $permission, string $resource, array $context): bool
    {
        // Get session-cached permissions
        $userPermissions = $this->getUserPermissions();

        // Try cached permissions first (fast path)
        if (!empty($userPermissions)) {
            // Check for direct permission match
            if ($this->hasDirectPermission($userPermissions, $permission, $resource)) {
                return true;
            }

            // Check for wildcard permission match
            if ($this->hasWildcardPermission($userPermissions, $permission, $resource)) {
                return true;
            }

            // Check for role-based permissions
            if ($this->hasRoleBasedPermission($permission, $resource, $context)) {
                return true;
            }
        }

        // Fallback: Use PermissionHelper for authoritative check
        // This handles cases where:
        // - Session cache is incomplete
        // - Dynamic permissions not cached
        // - RBAC provider has additional permissions
        $userUuid = $this->getUserUuid();
        if (!$userUuid) {
            return false;
        }

        try {
            // Create permission context for the check
            $permissionContext = new \Glueful\Permissions\PermissionContext(
                data: $context,
                ipAddress: $this->requestMetadata['ip_address'] ?? null,
                userAgent: $this->requestMetadata['user_agent'] ?? null,
                requestId: $this->requestId
            );

            return \Glueful\Permissions\Helpers\PermissionHelper::hasPermission(
                $userUuid,
                $permission,
                $resource,
                $permissionContext->toArray()
            );
        } catch (\Throwable $e) {
            // Log error but don't throw - fail securely
            error_log("RequestUserContext permission fallback failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check for direct permission match in session data
     *
     * @param array $userPermissions User permissions from session
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @return bool True if direct match found
     */
    private function hasDirectPermission(array $userPermissions, string $permission, string $resource): bool
    {
        // Check resource-specific permissions
        if (isset($userPermissions[$resource]) && in_array($permission, $userPermissions[$resource])) {
            return true;
        }

        // Check system-wide permissions
        if (isset($userPermissions['system']) && in_array($permission, $userPermissions['system'])) {
            return true;
        }

        // Check flat permission array (backward compatibility)
        if (is_array($userPermissions) && in_array($permission, $userPermissions)) {
            return true;
        }

        return false;
    }

    /**
     * Check for wildcard permission match
     *
     * @param array $userPermissions User permissions from session
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @return bool True if wildcard match found
     */
    private function hasWildcardPermission(array $userPermissions, string $permission, string $resource): bool
    {
        $wildcardPermissions = array_merge(
            $userPermissions[$resource] ?? [],
            $userPermissions['system'] ?? [],
            is_array($userPermissions) ? $userPermissions : []
        );

        foreach ($wildcardPermissions as $userPerm) {
            if (fnmatch($userPerm, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for role-based permissions
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier
     * @param array $context Additional context
     * @return bool True if role grants permission
     */
    private function hasRoleBasedPermission(string $permission, string $resource, array $context): bool
    {
        // Acknowledge unused parameters for future enhancement
        unset($permission, $resource, $context);

        $userRoles = $this->getUserRoles();
        if (empty($userRoles)) {
            return false;
        }

        // For simple implementation, check if user has admin role
        if (in_array('admin', $userRoles) || in_array('administrator', $userRoles)) {
            return true;
        }

        // Additional role-based logic can be added here
        // This could integrate with RBAC provider for complex role hierarchies
        return false;
    }

    /**
     * Get request metadata for audit logging
     *
     * @return array Request metadata
     */
    public function getRequestMetadata(): array
    {
        return $this->requestMetadata;
    }

    /**
     * Get audit context data
     *
     * @return array Audit context with user and request data
     */
    public function getAuditContext(): array
    {
        $user = $this->getUser();

        return array_merge($this->requestMetadata, [
            'user_uuid' => $user?->uuid,
            'session_id' => $this->sessionData['session_id'] ?? null,
            'request_id' => $this->requestId,
            'is_authenticated' => $this->isAuthenticated(),
            'is_admin' => $this->isAdmin()
        ]);
    }

    /**
     * Cache custom user data
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @return self Fluent interface
     */
    public function cacheUserData(string $key, mixed $value): self
    {
        $this->userCapabilities[$key] = $value;
        return $this;
    }

    /**
     * Get cached user data
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed Cached value or default
     */
    public function getCachedUserData(string $key, mixed $default = null): mixed
    {
        return $this->userCapabilities[$key] ?? $default;
    }

    /**
     * Load user capabilities and roles
     *
     * @return void
     */
    private function loadUserCapabilities(): void
    {
        if (!$this->user || !$this->sessionData) {
            return;
        }

        // Pre-load common capabilities from session data
        // Note: permissions and roles are nested in the user object
        $capabilities = [
            'user_roles' => $this->sessionData['user']['roles'] ?? [],
            'user_permissions' => $this->sessionData['user']['permissions'] ?? [],
            'permission_hash' => $this->sessionData['user']['permission_hash'] ?? null
        ];

        $this->userCapabilities = array_merge($this->userCapabilities, $capabilities);
    }

    /**
     * Generate unique request ID
     *
     * @return string Request ID
     */
    private static function generateRequestId(): string
    {
        return $_SERVER['HTTP_X_REQUEST_ID'] ?? uniqid('req_', true);
    }

    /**
     * Clear cached permission result
     *
     * @param string|null $key Specific cache key or null for all
     * @return self Fluent interface
     */
    public function clearPermissionCache(?string $key = null): self
    {
        if ($key === null) {
            $this->permissionCache = [];
        } else {
            unset($this->permissionCache[$key]);
        }

        return $this;
    }

    /**
     * Update token after refresh
     *
     * Updates the cached token and refreshes session data without losing
     * other cached information. This is used when tokens are refreshed
     * within the same request.
     *
     * @param string $newToken New access token
     * @return self Fluent interface
     */
    public function updateToken(string $newToken): self
    {
        // Update the cached token
        $this->token = $newToken;

        // Clear only authentication-related caches
        $this->sessionData = null;
        $this->user = null;

        // Keep permission cache since permissions haven't changed
        // Only clear the admin check since it may depend on session data
        unset($this->permissionCache['is_admin']);

        // Re-initialize with the new token
        $this->authAttempted = false;
        return $this->initialize();
    }

    /**
     * Refresh user data from session
     *
     * @return self Fluent interface
     */
    public function refresh(): self
    {
        $this->authAttempted = false;
        $this->permissionCache = [];
        $this->userCapabilities = [];
        $this->sessionData = null;
        $this->user = null;
        $this->token = null;
        $this->isAuthenticated = false;

        return $this->initialize();
    }

    /**
     * Get cached statistics
     *
     * @return array Cache statistics
     */
    public function getCacheStats(): array
    {
        return [
            'permission_cache_size' => count($this->permissionCache),
            'capabilities_cache_size' => count($this->userCapabilities),
            'auth_attempted' => $this->authAttempted,
            'is_authenticated' => $this->isAuthenticated,
            'has_user' => $this->user !== null,
            'has_session' => $this->sessionData !== null,
            'request_id' => $this->requestId
        ];
    }

    /**
     * Cleanup request-scoped instances
     *
     * @return void
     */
    public static function cleanup(): void
    {
        self::$instances = [];
    }

    /**
     * Destructor to log cache performance
     */
    public function __destruct()
    {
        // Log cache performance for monitoring
        if (config('app.debug', false)) {
            $stats = $this->getCacheStats();
            error_log("RequestUserContext stats: " . json_encode($stats));
        }
    }
}
