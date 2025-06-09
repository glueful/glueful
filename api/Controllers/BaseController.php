<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Auth\AuthBootstrap;
use Glueful\Auth\AuthenticationManager;
use Glueful\Repository\RepositoryFactory;
use Glueful\Helpers\DatabaseConnectionTrait;
use Glueful\Logging\AuditLogger;
use Glueful\Logging\AuditEvent;
use Glueful\Permissions\Helpers\PermissionHelper;
use Glueful\Permissions\Exceptions\UnauthorizedException;
use Glueful\Permissions\PermissionContext;
use Glueful\Permissions\PermissionManager;
use Glueful\Models\User;
use Glueful\Security\RateLimiter;
use Glueful\Security\AdaptiveRateLimiter;
use Glueful\Exceptions\RateLimitExceededException;
use Glueful\Exceptions\SecurityException;
use Glueful\Http\Response;
use Glueful\Cache\CacheEngine;
use Glueful\Database\QueryCacheService;
use Glueful\Cache\EdgeCacheService;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base Controller
 *
 * Provides core authentication and permission functionality for all controllers.
 *
 * @package Glueful\Controllers
 */
abstract class BaseController
{
    use DatabaseConnectionTrait;

    protected AuthenticationManager $authManager;
    protected RepositoryFactory $repositoryFactory;
    protected AuditLogger $auditLogger;
    protected ?User $currentUser = null;
    protected ?string $currentToken = null;
    protected Request $request;

    public function __construct(
        ?RepositoryFactory $repositoryFactory = null,
        ?AuthenticationManager $authManager = null,
        ?AuditLogger $auditLogger = null,
        ?Request $request = null
    ) {
        // Initialize authentication system
        $this->authManager = $authManager ?? AuthBootstrap::getManager();

        // Initialize repository factory
        $this->repositoryFactory = $repositoryFactory ?? new RepositoryFactory();

        // Initialize audit logger
        $this->auditLogger = $auditLogger ?? AuditLogger::getInstance();

        // Set request
        $this->request = $request ?? Request::createFromGlobals();

        // Get user data from request attributes (set by middleware)
        $userData = $this->request->attributes->get('user');
        if ($userData) {
            $this->currentUser = User::fromArray($userData);
            $this->currentToken = $this->extractToken($this->request);
        }
    }

    /**
     * Check if current user has a specific permission
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier (default: 'system')
     * @param array $context Additional context for permission check
     * @return bool True if user has permission
     */
    protected function can(string $permission, string $resource = 'system', array $context = []): bool
    {
        if (!$this->currentUser) {
            return false;
        }

        // Admin users have all permissions
        if ($this->isAdmin()) {
            return true;
        }

        // Check if permission provider is available
        if (!$this->hasPermissionProvider('permission_check')) {
            return false;
        }

        $permissionContext = new PermissionContext(
            data: $context,
            ipAddress: $this->request->getClientIp(),
            userAgent: $this->request->headers->get('User-Agent'),
            requestId: $this->request->headers->get('X-Request-ID')
        );

        return PermissionHelper::hasPermission(
            $this->currentUser->uuid,
            $permission,
            $resource,
            $permissionContext->toArray()
        );
    }

    /**
     * Require specific permission for the current user
     *
     * @param string $permission Permission to check
     * @param string $resource Resource identifier (default: 'system')
     * @param array $context Additional context for permission check
     * @throws UnauthorizedException If permission is denied
     */
    protected function requirePermission(
        string $permission,
        string $resource = 'system',
        array $context = []
    ): void {
        if (!$this->currentUser) {
            throw new UnauthorizedException('Authentication required', '401', 'Please log in to access this resource');
        }

        // Check if permission provider is available
        $permissionManager = PermissionManager::getInstance();
        if (!$permissionManager->hasActiveProvider()) {
            // Log as error since this is a required operation
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                'permission_required_no_provider',
                AuditEvent::SEVERITY_ERROR,
                [
                    'user_uuid' => $this->currentUser->uuid,
                    'permission' => $permission,
                    'resource' => $resource,
                    'message' => 'No permission provider available',
                    'controller' => static::class
                ]
            );

            throw new UnauthorizedException(
                'Permission system unavailable',
                '503',
                'The permission system is currently unavailable. Please try again later.'
            );
        }

        if (!$this->can($permission, $resource, $context)) {
            $permissionContext = new PermissionContext(
                data: array_merge([
                    'user_uuid' => $this->currentUser->uuid,
                    'permission' => $permission,
                    'resource' => $resource
                ], $context),
                ipAddress: $this->request->getClientIp(),
                userAgent: $this->request->headers->get('User-Agent'),
                requestId: $this->request->headers->get('X-Request-ID')
            );

            $this->auditLogger->audit(
                'security',
                'permission_denied',
                AuditEvent::SEVERITY_WARNING,
                $permissionContext->toArray()
            );

            throw new UnauthorizedException(
                'Insufficient permissions',
                '403',
                sprintf('You do not have permission to %s on %s', $permission, $resource)
            );
        }
    }

    /**
     * Check if current user has any of the specified permissions
     *
     * @param array $permissions Array of permissions to check
     * @param string $resource Resource identifier (default: 'system')
     * @param array $context Additional context for permission check
     * @return bool True if user has at least one permission
     */
    protected function canAny(array $permissions, string $resource = 'system', array $context = []): bool
    {
        if (!$this->currentUser) {
            return false;
        }

        // Admin users have all permissions
        if ($this->isAdmin()) {
            return true;
        }

        // Check if permission provider is available
        if (!$this->hasPermissionProvider('permission_check_any')) {
            return false;
        }

        $permissionContext = new PermissionContext(
            data: $context,
            ipAddress: $this->request->getClientIp(),
            userAgent: $this->request->headers->get('User-Agent'),
            requestId: $this->request->headers->get('X-Request-ID')
        );

        return PermissionHelper::hasAnyPermission(
            $this->currentUser->uuid,
            $permissions,
            $resource,
            $permissionContext->toArray()
        );
    }

    /**
     * Check if a permission provider is available
     *
     * @param string|null $action Optional action being performed for logging
     * @return bool True if provider is available, false otherwise
     */
    protected function hasPermissionProvider(?string $action = null): bool
    {
        $permissionManager = PermissionManager::getInstance();

        if (!$permissionManager->hasActiveProvider()) {
            // Log warning when no provider is available
            $this->auditLogger->audit(
                AuditEvent::CATEGORY_SYSTEM,
                $action ?? 'permission_provider_check',
                AuditEvent::SEVERITY_WARNING,
                [
                    'user_uuid' => $this->currentUser?->uuid,
                    'message' => 'No permission provider available',
                    'controller' => static::class
                ]
            );
            return false;
        }

        return true;
    }

    /**
     * Check if the current user is an admin
     *
     * @return bool True if user is an admin
     */
    protected function isAdmin(): bool
    {
        return $this->currentUser?->isAdmin ?? false;
    }

    /**
     * Get current authenticated user data
     *
     * @return User|null Current user data
     */
    protected function getCurrentUser(): ?User
    {
        return $this->currentUser;
    }

    /**
     * Get current authenticated user UUID
     *
     * @return string|null Current user UUID
     */
    protected function getCurrentUserUuid(): ?string
    {
        return $this->currentUser?->uuid;
    }

    /**
     * Extract token from request
     *
     * @param Request $request
     * @return string|null
     */
    private function extractToken(Request $request): ?string
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader) {
            return null;
        }

        return strpos($authHeader, 'Bearer ') === 0
            ? substr($authHeader, 7)
            : $authHeader;
    }

    /**
     * Rate limit an action with configurable parameters
     *
     * @param string $action Action identifier
     * @param int|null $maxAttempts Maximum attempts (null for config default)
     * @param int|null $windowSeconds Time window in seconds (null for config default)
     * @param bool $useAdaptive Whether to use adaptive rate limiting
     * @throws RateLimitExceededException If rate limit is exceeded
     */
    protected function rateLimit(
        string $action,
        ?int $maxAttempts = null,
        ?int $windowSeconds = null,
        bool $useAdaptive = true
    ): void {
        // Use config defaults if not specified
        $maxAttempts = $maxAttempts ?? config('security.rate_limiter.default_max_attempts', 60);
        $windowSeconds = $windowSeconds ?? config('security.rate_limiter.default_window_seconds', 60);

        // Create unique key for this controller action
        $key = sprintf(
            '%s:%s:%s',
            static::class,
            $action,
            $this->currentUser?->uuid ?? $this->request->getClientIp()
        );

        if ($useAdaptive && config('security.rate_limiter.enable_adaptive', true)) {
            // Use adaptive rate limiter with behavior analysis
            $limiter = new AdaptiveRateLimiter(
                $key,
                $maxAttempts,
                $windowSeconds,
                [
                    'controller' => static::class,
                    'action' => $action,
                    'user_uuid' => $this->currentUser?->uuid,
                    'ip' => $this->request->getClientIp(),
                    'user_agent' => $this->request->headers->get('User-Agent'),
                ],
                config('security.rate_limiter.enable_distributed', false)
            );

            // Check behavior score for additional security
            if ($limiter->getBehaviorScore() > 0.8) {
                throw new RateLimitExceededException(
                    'Suspicious behavior detected. Please try again later.',
                    $limiter->getRetryAfter()
                );
            }
        } else {
            // Use standard rate limiter
            $limiter = new RateLimiter($key, $maxAttempts, $windowSeconds);
        }

        if (!$limiter->attempt()) {
            throw new RateLimitExceededException(
                sprintf(
                    'Rate limit exceeded for %s. Please try again in %d seconds.',
                    $action,
                    $limiter->getRetryAfter()
                ),
                $limiter->getRetryAfter()
            );
        }
    }

    /**
     * Apply method-specific rate limiting
     *
     * @param string|null $method Method name (auto-detected if null)
     * @param array|null $customLimits Custom rate limits
     * @throws RateLimitExceededException If rate limit is exceeded
     */
    protected function rateLimitMethod(?string $method = null, ?array $customLimits = null): void
    {
        $method = $method ?? debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];

        // Check for controller-specific rate limit configuration
        $configKey = sprintf('security.rate_limits.controllers.%s.%s', static::class, $method);
        $methodLimits = config($configKey, $customLimits);

        if (!$methodLimits) {
            // Fall back to HTTP method-based limits
            $httpMethod = $this->request->getMethod();
            $methodLimits = config("security.rate_limits.methods.{$httpMethod}", [
                'attempts' => 60,
                'window' => 60,
                'adaptive' => true
            ]);
        }

        $this->rateLimit(
            $method,
            $methodLimits['attempts'] ?? 60,
            $methodLimits['window'] ?? 60,
            $methodLimits['adaptive'] ?? true
        );
    }

    /**
     * Apply resource-based rate limiting
     *
     * @param string $resource Resource identifier
     * @param string $operation Operation type (read, write, delete, export, bulk)
     * @param int|null $maxAttempts Override max attempts
     * @param int|null $windowSeconds Override window seconds
     * @throws RateLimitExceededException If rate limit is exceeded
     */
    protected function rateLimitResource(
        string $resource,
        string $operation = 'access',
        ?int $maxAttempts = null,
        ?int $windowSeconds = null
    ): void {
        // Different limits for different operations
        $operationLimits = config('security.rate_limits.operations', [
            'read' => ['attempts' => 100, 'window' => 60],
            'write' => ['attempts' => 30, 'window' => 60],
            'delete' => ['attempts' => 10, 'window' => 60],
            'export' => ['attempts' => 5, 'window' => 300],
            'bulk' => ['attempts' => 3, 'window' => 600]
        ]);

        $limits = $operationLimits[$operation] ?? ['attempts' => 60, 'window' => 60];

        $this->rateLimit(
            sprintf('%s:%s', $resource, $operation),
            $maxAttempts ?? $limits['attempts'],
            $windowSeconds ?? $limits['window']
        );
    }

    /**
     * Apply multi-level rate limiting
     *
     * @param array $limits Array of rate limit configurations by level
     * @throws RateLimitExceededException If any rate limit is exceeded
     */
    protected function multiLevelRateLimit(array $limits): void
    {
        foreach ($limits as $level => $config) {
            $key = match ($level) {
                'ip' => $this->request->getClientIp(),
                'user' => $this->currentUser?->uuid ?? 'anonymous',
                'endpoint' => $this->request->getPathInfo(),
                'global' => 'global',
                default => $level
            };

            $this->rateLimit(
                sprintf('%s:%s', $level, $key),
                $config['attempts'],
                $config['window'],
                $config['adaptive'] ?? false
            );
        }
    }

    /**
     * Apply conditional rate limiting based on user type
     *
     * @param string $action Action identifier
     * @throws RateLimitExceededException If rate limit is exceeded
     */
    protected function conditionalRateLimit(string $action): void
    {
        $limits = match (true) {
            $this->isAdmin() => [
                'attempts' => 1000,
                'window' => 60,
                'adaptive' => false
            ],
            $this->can('premium_features') => [
                'attempts' => 200,
                'window' => 60,
                'adaptive' => true
            ],
            $this->currentUser !== null => [
                'attempts' => 100,
                'window' => 60,
                'adaptive' => true
            ],
            default => [
                'attempts' => 30,
                'window' => 60,
                'adaptive' => true
            ]
        };

        $this->rateLimit($action, $limits['attempts'], $limits['window'], $limits['adaptive']);
    }

    /**
     * Get rate limit headers for response
     *
     * @return array Rate limit headers
     */
    protected function getRateLimitHeaders(): array
    {
        $key = sprintf(
            '%s:%s:%s',
            static::class,
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'],
            $this->currentUser?->uuid ?? $this->request->getClientIp()
        );

        $limiter = new RateLimiter(
            $key,
            config('security.rate_limiter.default_max_attempts', 60),
            config('security.rate_limiter.default_window_seconds', 60)
        );

        return [
            'X-RateLimit-Limit' => config('security.rate_limiter.default_max_attempts', 60),
            'X-RateLimit-Remaining' => $limiter->remaining(),
            'X-RateLimit-Reset' => time() + $limiter->getRetryAfter(),
            'X-RateLimit-Policy' => sprintf(
                '%d;w=%d',
                config('security.rate_limiter.default_max_attempts', 60),
                config('security.rate_limiter.default_window_seconds', 60)
            )
        ];
    }

    /**
     * Require low risk behavior score for sensitive operations
     *
     * @param float $maxScore Maximum allowed behavior score
     * @param string|null $operation Operation description
     * @throws UnauthorizedException If not authenticated
     * @throws SecurityException If behavior score is too high
     */
    protected function requireLowRiskBehavior(float $maxScore = 0.6, ?string $operation = null): void
    {
        if (!$this->currentUser) {
            throw new UnauthorizedException(
                'Authentication required for this operation',
                '401',
                'Please log in to access this resource'
            );
        }

        $limiter = new AdaptiveRateLimiter(
            "user:{$this->currentUser->uuid}",
            1, // Dummy values
            1,
            [
                'operation' => $operation ?? 'sensitive_action',
                'controller' => static::class,
                'ip' => $this->request->getClientIp()
            ],
            false
        );

        $behaviorScore = $limiter->getBehaviorScore();

        if ($behaviorScore > $maxScore) {
            $this->auditLogger->audit(
                'security',
                'high_risk_behavior_blocked',
                AuditEvent::SEVERITY_WARNING,
                [
                    'user_uuid' => $this->currentUser->uuid,
                    'behavior_score' => $behaviorScore,
                    'max_allowed_score' => $maxScore,
                    'operation' => $operation,
                    'controller' => static::class
                ]
            );

            throw new SecurityException(
                'This operation requires additional verification due to unusual account activity',
                Response::HTTP_FORBIDDEN
            );
        }
    }

    /**
     * Reset rate limits for an identifier
     *
     * @param string|null $identifier Rate limit identifier to reset
     * @throws UnauthorizedException If insufficient permissions
     */
    protected function resetRateLimits(?string $identifier = null): void
    {
        $this->requirePermission('system.rate_limits.reset');

        $key = $identifier ?? sprintf(
            '%s:%s',
            static::class,
            $this->currentUser?->uuid ?? $this->request->getClientIp()
        );

        $limiter = new RateLimiter($key, 1, 1);
        $limiter->reset();

        $this->auditLogger->audit(
            'admin',
            'rate_limit_reset',
            AuditEvent::SEVERITY_INFO,
            [
                'admin_uuid' => $this->currentUser->uuid,
                'reset_key' => $key,
                'controller' => static::class
            ]
        );
    }

    /**
     * Apply burst-aware rate limiting
     *
     * @param string $action Action identifier
     * @param int $burstSize Maximum burst size
     * @param int $sustainedRate Sustained rate limit
     * @param int $windowSeconds Time window in seconds
     * @throws RateLimitExceededException If rate limit is exceeded
     */
    protected function burstRateLimit(
        string $action,
        int $burstSize = 10,
        int $sustainedRate = 60,
        int $windowSeconds = 60
    ): void {
        // Allow initial burst
        $burstKey = sprintf('%s:burst', $action);
        $sustainedKey = sprintf('%s:sustained', $action);

        try {
            // Check burst limit first (shorter window)
            $this->rateLimit($burstKey, $burstSize, 10, false);
        } catch (RateLimitExceededException $e) {
            // Burst exceeded, check sustained rate
            $this->rateLimit($sustainedKey, $sustainedRate, $windowSeconds, true);
        }
    }

    /**
     * Add rate limit headers to response
     *
     * @param Response $response Response object
     * @return Response Response with rate limit headers
     */
    protected function withRateLimitHeaders(Response $response): Response
    {
        $headers = $this->getRateLimitHeaders();

        foreach ($headers as $header => $value) {
            header($header . ': ' . $value);
        }

        return $response;
    }

    /**
     * Cache response with automatic cache key generation
     *
     * @param string $key Cache key identifier
     * @param callable $callback Callback to generate data if not cached
     * @param int $ttl Time to live in seconds
     * @param array $tags Cache tags for invalidation
     * @return mixed Cached or generated data
     */
    protected function cacheResponse(
        string $key,
        callable $callback,
        int $ttl = 3600,
        array $tags = []
    ): mixed {
        // Generate cache key with controller context
        $cacheKey = sprintf(
            'controller:%s:%s:%s',
            static::class,
            $key,
            md5(serialize([
                $this->request->query->all(),
                $this->currentUser?->uuid,
                $this->request->headers->get('Accept'),
                $this->request->headers->get('Accept-Language')
            ]))
        );

        // Add user-specific tags if authenticated
        if ($this->currentUser) {
            $tags[] = 'user:' . $this->currentUser->uuid;
        }

        return CacheEngine::remember($cacheKey, $callback, $ttl);
    }

    /**
     * Cache query results with repository integration
     *
     * @param string $repository Repository name
     * @param string $method Repository method name
     * @param array $args Method arguments
     * @param int $ttl Time to live in seconds
     * @param array $tags Additional cache tags
     * @return mixed Cached query results
     */
    protected function cacheQuery(
        string $repository,
        string $method,
        array $args = [],
        int $ttl = 3600,
        array $tags = []
    ): mixed {
        $repo = $this->repositoryFactory->getRepository($repository);

        // Use QueryCacheService for intelligent query caching
        $cacheService = new QueryCacheService();

        // Add repository-specific tags
        $tags[] = 'repository:' . $repository;
        $tags[] = 'method:' . $method;

        // Note: $ttl and $tags parameters are prepared for future use
        // when QueryCacheService supports them
        return $cacheService->cacheRepositoryMethod($repo, $method, $args);
    }

    /**
     * Cache paginated results
     *
     * @param string $repository Repository name
     * @param int $page Page number
     * @param int $perPage Items per page
     * @param array $conditions Query conditions
     * @param array $orderBy Order by criteria
     * @param int $ttl Cache time to live
     * @return array Paginated results
     */
    protected function cachePaginatedResponse(
        string $repository,
        int $page = 1,
        int $perPage = 25,
        array $conditions = [],
        array $orderBy = ['created_at' => 'DESC'],
        int $ttl = 600
    ): array {
        $cacheKey = sprintf(
            'paginated:%s:page_%d:per_%d',
            $repository,
            $page,
            $perPage
        );

        return $this->cacheResponse($cacheKey, function () use ($repository, $page, $perPage, $conditions, $orderBy) {
            $repo = $this->repositoryFactory->getRepository($repository);
            return $repo->paginate($page, $perPage, $conditions, $orderBy);
        }, $ttl, ['pagination', 'repository:' . $repository]);
    }

    /**
     * Add cache headers to response
     *
     * @param Response $response Response object
     * @param array $options Cache options
     * @return Response Response with cache headers
     */
    protected function withCacheHeaders(
        Response $response,
        array $options = []
    ): Response {
        $defaults = [
            'public' => true,
            'max_age' => 3600,
            's_maxage' => 3600,
            'must_revalidate' => true,
            'etag' => true,
            'vary' => ['Accept', 'Authorization']
        ];

        $settings = array_merge($defaults, $options);

        // Set cache control headers
        $cacheControl = [];

        if ($settings['public']) {
            $cacheControl[] = 'public';
        } else {
            $cacheControl[] = 'private';
        }

        $cacheControl[] = 'max-age=' . $settings['max_age'];

        if ($settings['s_maxage'] !== null) {
            $cacheControl[] = 's-maxage=' . $settings['s_maxage'];
        }

        if ($settings['must_revalidate']) {
            $cacheControl[] = 'must-revalidate';
        }

        header('Cache-Control: ' . implode(', ', $cacheControl));

        // Set Vary header
        if (!empty($settings['vary'])) {
            header('Vary: ' . implode(', ', $settings['vary']));
        }

        return $response;
    }

    /**
     * Create cached response with ETag validation
     *
     * @param mixed $data Response data
     * @param string $cacheKey Cache key
     * @param int $ttl Time to live
     * @param array $tags Cache tags
     * @return Response Cached response
     */
    protected function cachedResponse(
        mixed $data,
        string $cacheKey,
        int $ttl = 3600,
        array $tags = []
    ): Response {
        // Generate ETag from data
        $etag = '"' . md5(serialize($data)) . '"';

        // Check If-None-Match header
        $clientEtag = $this->request->headers->get('If-None-Match');
        if ($clientEtag === $etag) {
            // Return 304 Not Modified
            http_response_code(304);
            header('ETag: ' . $etag);
            exit;
        }

        // Cache the data
        $this->cacheResponse($cacheKey, fn() => $data, $ttl, $tags);

        // Create response
        $response = Response::ok($data);

        // Add cache headers
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=' . $ttl . ', must-revalidate');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');

        return $response;
    }

    /**
     * Cache with permission awareness
     *
     * @param string $key Cache key
     * @param callable $callback Data generator callback
     * @param int $defaultTtl Default time to live
     * @return mixed Cached data
     */
    protected function cacheByPermission(
        string $key,
        callable $callback,
        int $defaultTtl = 3600
    ): mixed {
        $ttl = $defaultTtl;
        $tags = ['user:' . ($this->currentUser?->uuid ?? 'anonymous')];

        // Adjust cache duration based on user type
        if ($this->isAdmin()) {
            $ttl = 300; // 5 minutes for admins (fresher data)
            $tags[] = 'admin_cache';
        } elseif ($this->currentUser && $this->can('premium_features')) {
            $ttl = 7200; // 2 hours for premium users
            $tags[] = 'premium_cache';
        } elseif ($this->currentUser) {
            $ttl = 3600; // 1 hour for regular users
            $tags[] = 'user_cache';
        } else {
            $ttl = 1800; // 30 minutes for anonymous users
            $tags[] = 'anonymous_cache';
        }

        return $this->cacheResponse($key, $callback, $ttl, $tags);
    }

    /**
     * Invalidate cache by tags
     *
     * @param array $tags Cache tags to invalidate
     */
    protected function invalidateCache(array $tags = []): void
    {
        if (empty($tags)) {
            // Invalidate user-specific cache by default
            if ($this->currentUser) {
                $tags = ['user:' . $this->currentUser->uuid];
            }
        }

        CacheEngine::invalidateTags($tags);

        // Log cache invalidation
        $this->auditLogger->audit(
            AuditEvent::CATEGORY_SYSTEM,
            'cache_invalidated',
            AuditEvent::SEVERITY_INFO,
            [
                'tags' => $tags,
                'controller' => static::class,
                'user_uuid' => $this->currentUser?->uuid
            ]
        );
    }

    /**
     * Invalidate resource-specific cache
     *
     * @param string $resource Resource name
     * @param string|null $id Resource ID
     */
    protected function invalidateResourceCache(string $resource, ?string $id = null): void
    {
        $tags = ['repository:' . $resource];

        if ($id) {
            $tags[] = $resource . ':' . $id;
        }

        $this->invalidateCache($tags);
    }

    /**
     * Warm cache with predefined keys
     *
     * @param array $keys Cache keys with TTL values
     * @param callable $dataProvider Data provider callback
     */
    protected function warmCache(array $keys, callable $dataProvider): void
    {
        foreach ($keys as $key => $ttl) {
            $this->cacheResponse($key, function () use ($key, $dataProvider) {
                return $dataProvider($key);
            }, $ttl);
        }
    }

    /**
     * Conditional caching based on request context
     *
     * @param string $key Cache key
     * @param callable $callback Data generator
     * @param array $conditions Cache conditions
     * @return mixed Data
     */
    protected function conditionalCache(
        string $key,
        callable $callback,
        array $conditions = []
    ): mixed {
        $shouldCache = true;
        $ttl = 3600;

        // Check conditions
        foreach ($conditions as $condition => $value) {
            switch ($condition) {
                case 'method':
                    if (!in_array($this->request->getMethod(), (array)$value)) {
                        $shouldCache = false;
                    }
                    break;

                case 'authenticated':
                    if ($value && !$this->currentUser) {
                        $shouldCache = false;
                    }
                    break;

                case 'min_permission':
                    if (!$this->can($value)) {
                        $shouldCache = false;
                    }
                    break;

                case 'ttl':
                    $ttl = $value;
                    break;
            }
        }

        if (!$shouldCache) {
            return $callback();
        }

        return $this->cacheResponse($key, $callback, $ttl);
    }

    /**
     * Add edge cache headers for CDN
     *
     * @param Response $response Response object
     * @param string $pattern Route pattern
     * @param int $ttl Time to live
     * @return Response Response with edge cache headers
     */
    protected function edgeCacheResponse(
        Response $response,
        string $pattern,
        int $ttl = 3600
    ): Response {
        // Add edge cache headers based on route pattern
        $edgeService = new EdgeCacheService();
        $contentType = $this->request->headers->get('Accept', 'application/json');
        $headers = $edgeService->generateCacheHeaders($pattern, $contentType);

        foreach ($headers as $header => $value) {
            header($header . ': ' . $value);
        }

        // Add surrogate keys for targeted purging
        $surrogateKeys = [
            'controller:' . basename(str_replace('\\', '/', static::class)),
            'user:' . ($this->currentUser?->uuid ?? 'anonymous'),
            'pattern:' . $pattern
        ];

        header('Surrogate-Key: ' . implode(' ', $surrogateKeys));

        // Note: $ttl parameter is prepared for future use
        return $response;
    }

    /**
     * Track cache performance metrics
     *
     * @param string $key Cache key
     * @param bool $hit Whether it was a cache hit
     * @param float $duration Operation duration
     */
    protected function trackCachePerformance(string $key, bool $hit, float $duration): void
    {
        // Track cache hit/miss metrics
        $metrics = [
            'key' => $key,
            'hit' => $hit,
            'duration_ms' => $duration * 1000,
            'controller' => static::class,
            'user_uuid' => $this->currentUser?->uuid,
            'timestamp' => time()
        ];

        // Store metrics (could be sent to monitoring service)
        CacheEngine::zadd('cache_metrics', [json_encode($metrics) => time()]);

        // Cleanup old metrics (keep last 24 hours)
        CacheEngine::zremrangebyscore('cache_metrics', '-inf', (string)(time() - 86400));
    }

    /**
     * Cache fragment of response
     *
     * @param string $fragment Fragment identifier
     * @param callable $callback Data generator
     * @param int $ttl Time to live
     * @param array $dependencies Cache dependencies
     * @return mixed Cached fragment
     */
    protected function cacheFragment(
        string $fragment,
        callable $callback,
        int $ttl = 1800,
        array $dependencies = []
    ): mixed {
        $cacheKey = sprintf(
            'fragment:%s:%s:%s',
            static::class,
            $fragment,
            md5(serialize($dependencies))
        );

        $startTime = microtime(true);
        // Check if key exists by attempting to get it
        $existingValue = CacheEngine::get($cacheKey);
        $cached = $existingValue !== null;

        $result = $this->cacheResponse($cacheKey, $callback, $ttl, ['fragment', 'fragment:' . $fragment]);

        $this->trackCachePerformance($cacheKey, $cached, microtime(true) - $startTime);

        return $result;
    }

    /**
     * Cache multiple operations in batch
     *
     * @param array $operations Array of cache operations
     * @return array Results array
     */
    protected function cacheMultiple(array $operations): array
    {
        $results = [];

        // Process each operation
        foreach ($operations as $key => $operation) {
            // Try to get from cache first
            $cacheKey = sprintf('controller:%s:%s', static::class, $key);
            $cachedValue = CacheEngine::get($cacheKey);

            if ($cachedValue !== null) {
                $results[$key] = $cachedValue;
            } else {
                // Execute callback and cache result
                $results[$key] = $this->cacheResponse(
                    $key,
                    $operation['callback'],
                    $operation['ttl'] ?? 3600,
                    $operation['tags'] ?? []
                );
            }
        }

        return $results;
    }
}
