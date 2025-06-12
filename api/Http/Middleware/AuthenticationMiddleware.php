<?php

declare(strict_types=1);

namespace Glueful\Http\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Glueful\Auth\AuthenticationManager;
use Glueful\Auth\AuthBootstrap;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\Exceptions\AuthenticationException;

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

    /** @var ContainerInterface|null DI Container */
    private ?ContainerInterface $container;

    /**
     * Create a new authentication middleware
     *
     * @param bool $requiresAdmin Whether to require admin privileges
     * @param AuthenticationManager|null $authManager Optional custom auth manager
     * @param array $providerNames Provider names to try in sequence
     * @param ContainerInterface|null $container DI Container instance
     */
    public function __construct(
        bool $requiresAdmin = false,
        ?AuthenticationManager $authManager = null,
        array $providerNames = [],
        ?ContainerInterface $container = null
    ) {
        $this->container = $container ?? $this->getDefaultContainer();
        $this->requiresAdmin = $requiresAdmin;

        // Use provided AuthManager, get from DI container, or fall back to AuthBootstrap
        if ($authManager) {
            $this->authManager = $authManager;
        } elseif ($this->container && $this->container->has(AuthenticationManager::class)) {
            $this->authManager = $this->container->get(AuthenticationManager::class);
        } else {
            $this->authManager = AuthBootstrap::getManager();
        }

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
            // Let exceptions bubble up instead of returning response directly
            throw new AuthenticationException('Authentication failed. Please provide valid credentials.');
        }

        // Attach user data to request attributes for controllers to access
        $request->attributes->set('user', $userData);

        // Check admin permissions if required
        if ($this->requiresAdmin && !$this->authManager->isAdmin($userData)) {
            throw new AuthenticationException('Insufficient permissions, admin access required');
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
     * @throws AuthenticationException If token is expired
     */
    private function authenticate(Request $request): ?array
    {
        // If specific providers are requested, try them in sequence
        if (!empty($this->providerNames)) {
            $userData = $this->authManager->authenticateWithProviders($this->providerNames, $request);
        } else {
            // Otherwise use the default provider
            $userData = $this->authManager->authenticate($request);
        }

        // If authentication succeeded, validate token expiration
        if ($userData) {
            $this->validateTokenExpiration($userData, $request);
        }

        return $userData;
    }

    /**
     * Validate token expiration from user data
     *
     * @param array $userData User session data
     * @param Request $request The HTTP request (unused but kept for future use)
     * @throws AuthenticationException If tokens are expired
     */
    private function validateTokenExpiration(array $userData, Request $request): void
    {
        $now = time();
        $currentTime = date('Y-m-d H:i:s');

        // Check access token expiration
        if (isset($userData['access_expires_at'])) {
            $accessExpiresAt = strtotime($userData['access_expires_at']);
            if ($accessExpiresAt !== false && $accessExpiresAt < $now) {
                // Check if refresh token is still valid
                if (isset($userData['refresh_expires_at'])) {
                    $refreshExpiresAt = strtotime($userData['refresh_expires_at']);
                    if ($refreshExpiresAt !== false && $refreshExpiresAt > $now) {
                        // Refresh token is valid - should trigger token refresh
                        throw new AuthenticationException('Access token expired. Please refresh your token.', 401, [
                            'error_code' => 'TOKEN_EXPIRED',
                            'refresh_available' => true
                        ]);
                    }
                }

                // Both tokens expired or refresh not available
                throw new AuthenticationException('Session expired. Please log in again.', 401, [
                    'error_code' => 'SESSION_EXPIRED',
                    'refresh_available' => false
                ]);
            }
        }

        // Check refresh token expiration (for completeness)
        if (isset($userData['refresh_expires_at'])) {
            $refreshExpiresAt = strtotime($userData['refresh_expires_at']);
            if ($refreshExpiresAt !== false && $refreshExpiresAt < $now) {
                throw new AuthenticationException('Session expired. Please log in again.', 401, [
                    'error_code' => 'SESSION_EXPIRED',
                    'refresh_available' => false
                ]);
            }
        }

        // Validate JWT token expiration if present
        if (isset($userData['access_token'])) {
            // Ensure access_token is a string (JWT token), not an array
            if (is_string($userData['access_token']) && class_exists('\\Glueful\\Auth\\JWTService')) {
                try {
                    if (call_user_func(['\\Glueful\\Auth\\JWTService', 'isExpired'], $userData['access_token'])) {
                        error_log("AuthenticationMiddleware: JWT token is expired");
                        throw new AuthenticationException('Access token expired. Please refresh your token.', 401, [
                            'error_code' => 'TOKEN_EXPIRED',
                            'refresh_available' => isset($userData['refresh_token'])
                        ]);
                    }
                } catch (\Exception $e) {
                    error_log("AuthenticationMiddleware: JWT validation failed: " . $e->getMessage());
                    error_log("AuthenticationMiddleware: access_token type: " . gettype($userData['access_token']));
                    throw new AuthenticationException('Invalid token format.', 401, [
                        'error_code' => 'INVALID_TOKEN'
                    ]);
                }
            } else {
                error_log("AuthenticationMiddleware: access_token is not a valid JWT string");
                throw new AuthenticationException('Invalid token format.', 401, [
                    'error_code' => 'INVALID_TOKEN'
                ]);
            }
        }
    }

    /**
     * Get default container safely
     *
     * @return ContainerInterface|null
     */
    private function getDefaultContainer(): ?ContainerInterface
    {
        // Check if app() function exists (available when bootstrap is loaded)
        if (function_exists('app')) {
            // Let exceptions bubble up - container issues should be handled at application level
            return app();
        }

        return null;
    }
}
