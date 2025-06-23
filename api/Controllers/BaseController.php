<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Auth\AuthBootstrap;
use Glueful\Auth\AuthenticationManager;
use Glueful\Repository\RepositoryFactory;
use Glueful\Helpers\DatabaseConnectionTrait;
use Glueful\Controllers\Traits\AsyncAuditTrait;
use Glueful\Controllers\Traits\CachedUserContextTrait;
use Glueful\Controllers\Traits\AuthorizationTrait;
use Glueful\Controllers\Traits\RateLimitingTrait;
use Glueful\Controllers\Traits\ResponseCachingTrait;
use Glueful\Controllers\Traits\ResourceAuditingTrait;
use Glueful\Logging\AuditLogger;
use Glueful\Models\User;
use Glueful\Http\RequestUserContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base Controller
 *
 * Provides core functionality for all controllers through focused traits.
 * Each trait handles a specific responsibility for better maintainability.
 *
 * Traits used:
 * - DatabaseConnectionTrait: Database connection management
 * - AsyncAuditTrait: Asynchronous audit logging
 * - CachedUserContextTrait: Cached user context and permissions
 * - AuthorizationTrait: Authorization and permission checks
 * - RateLimitingTrait: Rate limiting functionality
 * - ResponseCachingTrait: Response and query caching
 * - ResourceAuditingTrait: Resource-specific audit logging
 *
 * @package Glueful\Controllers
 */
abstract class BaseController
{
    use DatabaseConnectionTrait;
    use AsyncAuditTrait;
    use CachedUserContextTrait;
    use AuthorizationTrait;
    use RateLimitingTrait;
    use ResponseCachingTrait;
    use ResourceAuditingTrait;

    /**
     * @var AuthenticationManager Authentication manager instance
     */
    protected AuthenticationManager $authManager;

    /**
     * @var RepositoryFactory Repository factory for creating repository instances
     */
    protected RepositoryFactory $repositoryFactory;

    /**
     * @var AuditLogger Audit logger instance
     */
    protected AuditLogger $auditLogger;

    /**
     * @var User|null Current authenticated user
     */
    protected ?User $currentUser = null;

    /**
     * @var string|null Current authentication token
     */
    protected ?string $currentToken = null;

    /**
     * @var Request Current HTTP request
     */
    protected Request $request;

    /**
     * @var RequestUserContext User context for caching
     */
    protected RequestUserContext $userContext;

    /**
     * BaseController constructor
     *
     * @param RepositoryFactory|null $repositoryFactory Repository factory instance
     * @param AuthenticationManager|null $authManager Authentication manager
     * @param AuditLogger|null $auditLogger Audit logger
     * @param Request|null $request HTTP request
     */
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

        // Set request - use provided request or get from container
        $this->request = $request ?? container()->get(Request::class);

        // Initialize request user context for cached authentication
        $this->userContext = RequestUserContext::getInstance()->initialize();

        // Set current user and token from context (cached)
        $this->currentUser = $this->userContext->getUser();
        $this->currentToken = $this->userContext->getToken();
    }
}
