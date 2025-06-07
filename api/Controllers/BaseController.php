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
    protected ?array $currentUser = null;
    protected ?string $currentUserUuid = null;
    protected ?string $currentToken = null;

    public function __construct(?RepositoryFactory $repositoryFactory = null)
    {
        // Initialize authentication system
        AuthBootstrap::initialize();
        $this->authManager = AuthBootstrap::getManager();

        // Initialize repository factory
        $this->repositoryFactory = $repositoryFactory ?? new RepositoryFactory();

        // Initialize audit logger
        $this->auditLogger = AuditLogger::getInstance();

        // Authenticate the current request
        $request = Request::createFromGlobals();
        $userData = $this->authManager->authenticateWithProviders(['jwt', 'api_key'], $request);

        if ($userData) {
            $this->currentUser = $userData;
            $this->currentUserUuid = $this->extractUserUuid($userData);
            $this->currentToken = $this->extractToken($request);
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
        if (!$this->currentUserUuid) {
            return false;
        }

        return PermissionHelper::hasPermission($this->currentUserUuid, $permission, $resource, $context);
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
        if (!$this->can($permission, $resource, $context)) {
            // Log permission denial
            $contextData = array_merge([
                'user_uuid' => $this->currentUserUuid,
                'permission' => $permission,
                'resource' => $resource,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ], $context);

            $this->auditLogger->audit(
                'security',
                'permission_denied',
                AuditEvent::SEVERITY_WARNING,
                $contextData
            );

            throw new UnauthorizedException('Insufficient permissions', '403', '');
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
        if (!$this->currentUserUuid) {
            return false;
        }

        return PermissionHelper::hasAnyPermission($this->currentUserUuid, $permissions, $resource, $context);
    }

    /**
     * Get current authenticated user data
     *
     * @return array|null Current user data
     */
    protected function getCurrentUser(): ?array
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
        return $this->currentUserUuid;
    }

    /**
     * Extract user UUID from authentication data
     *
     * @param array $authData Authentication data
     * @return string|null User UUID
     */
    private function extractUserUuid(array $authData): ?string
    {
        // Check common UUID locations in auth data
        if (isset($authData['user_uuid'])) {
            return $authData['user_uuid'];
        }

        if (isset($authData['uuid'])) {
            return $authData['uuid'];
        }

        if (isset($authData['user']['uuid'])) {
            return $authData['user']['uuid'];
        }

        if (isset($authData['data']['user']['uuid'])) {
            return $authData['data']['user']['uuid'];
        }

        return null;
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

        // Remove 'Bearer ' prefix if present
        if (strpos($authHeader, 'Bearer ') === 0) {
            return substr($authHeader, 7);
        }

        return $authHeader;
    }
}
