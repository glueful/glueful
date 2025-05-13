<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Repository\{RoleRepository, UserRepository};
use Glueful\Helpers\Request;
use Glueful\Auth\{AuthBootstrap, TokenManager};
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class AdminController
{
    private UserRepository $userRepository;
    private ConfigController $configController;
    private $authManager;
    private RoleRepository $roleRepo;

    public function __construct()
    {
        $this->userRepository = new UserRepository();
        $this->roleRepo = new RoleRepository();
        $this->configController = new ConfigController();

        // Initialize auth system
        AuthBootstrap::initialize();
        $this->authManager = AuthBootstrap::getManager();
    }

    /**
     * Admin login endpoint
     *
     * Authenticates admin users and verifies superuser role before creating session.
     *
     * @return mixed HTTP response
     */
    public function login()
    {
        try {
            $credentials = Request::getPostData();

            if (!isset($credentials['username']) || !isset($credentials['password'])) {
                return Response::error('Username and password are required', Response::HTTP_BAD_REQUEST)->send();
            }

            if (filter_var($credentials['username'], FILTER_VALIDATE_EMAIL)) {
                return Response::error('Email login not supported', Response::HTTP_BAD_REQUEST)->send();
                $user = $this->userRepository->findByEmail($credentials['username']);
            } else {
                $user = $this->userRepository->findByUsername($credentials['username']);
            }

            // Check if user has superuser role
            $userId = $user['uuid'];
            if (!$userId) {
                return Response::error('User does not exist', Response::HTTP_NOT_FOUND)->send();
            }
            // Check if user has superuser role
            if (!$this->roleRepo->userHasRole($userId, 'superuser')) {
                // Log unauthorized admin access attempt
                error_log("Unauthorized access attempt by user ID: $userId");
                return Response::error('Insufficient privileges', Response::HTTP_FORBIDDEN)->send();
            }

            // Create a Symfony request with credentials for authentication
            $request = new SymfonyRequest([], [], [], [], [], [], json_encode($credentials));
            $request->headers->set('Content-Type', 'application/json');

            // Authenticate using the admin authentication provider
            $userData = $this->authManager->authenticateWithProvider('admin', $request);

            if (!$userData) {
                return Response::error('Invalid credentials', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Log the admin access
            $this->authManager->logAccess($userData['user'], $request);

            return Response::ok($userData, 'Login successful')->send();
        } catch (\Exception $e) {
            error_log("Login error: " . $e->getMessage());
            return Response::error(
                'Login failed: ' . ($e->getMessage()),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

     /**
     * User logout
     *
     * Terminates user session and invalidates tokens.
     *
     * @return mixed HTTP response
     */
    public function logout()
    {
        try {
            $request = SymfonyRequest::createFromGlobals();
            $userData = $this->authenticate($request);

            if (!$userData) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Extract token for terminating session
            $token = $userData['token'] ?? null;

            if (!$token) {
                return Response::error('No valid token found', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Use TokenManager to revoke the session instead of the non-existent invalidateToken method
            $success = TokenManager::revokeSession($token);

            if ($success) {
                return Response::ok(null, 'Logged out successfully')->send();
            }

            return Response::error('Logout failed', Response::HTTP_BAD_REQUEST)->send();
        } catch (\Exception $e) {
            return Response::error(
                'Logout failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    // Database-related methods have been moved to DatabaseController
    // Use $this->databaseController to access those methods when needed

    // Migration-related methods have been moved to MigrationsController
    // Use $this->migrationsController to access those methods when needed

    /**
     * Get all configurations
     */
    public function getAllConfigs(): mixed
    {
        try {
            $configs = $this->configController->getConfigs();
            return Response::ok($configs, 'Configurations retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get configs error: " . $e->getMessage());
            return Response::error(
                'Failed to get configurations: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get configuration by filename
     */
    public function getConfig($filename): mixed
    {
        try {
            if (!isset($filename)) {
                return Response::error('Filename is required', Response::HTTP_BAD_REQUEST)->send();
            }

            $config = $this->configController->getConfigByFile($filename);

            if ($config === null) {
                return Response::error('Configuration file not found', Response::HTTP_NOT_FOUND)->send();
            }

            return Response::ok(['config' => $config], 'Configuration retrieved successfully')->send();
        } catch (\Exception $e) {
            error_log("Get config error: " . $e->getMessage());
            return Response::error(
                'Failed to get configuration: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Update configuration
     */
    public function updateConfig(): mixed
    {
        try {
            $data = Request::getPostData();

            if (!isset($data['filename']) || !isset($data['config'])) {
                return Response::error(
                    'Filename and configuration data are required',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            $success = $this->configController->updateConfig($data['filename'], $data['config']);

            if (!$success) {
                return Response::error('Failed to update configuration', Response::HTTP_BAD_REQUEST)->send();
            }

            return Response::ok(null, 'Configuration updated successfully')->send();
        } catch (\Exception $e) {
            error_log("Update config error: " . $e->getMessage());
            return Response::error(
                'Failed to update configuration: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Create new configuration
     */
    public function createConfig(): mixed
    {
        try {
            $data = Request::getPostData();

            if (!isset($data['filename']) || !isset($data['config'])) {
                return Response::error(
                    'Filename and configuration data are required',
                    Response::HTTP_BAD_REQUEST
                )->send();
            }

            $success = $this->configController->createConfig($data['filename'], $data['config']);

            if (!$success) {
                return Response::error('Failed to create configuration', Response::HTTP_BAD_REQUEST)->send();
            }

            return Response::ok(null, 'Configuration created successfully')->send();
        } catch (\Exception $e) {
            error_log("Create config error: " . $e->getMessage());
            return Response::error(
                'Failed to create configuration: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Authenticate a request using multiple authentication methods
     *
     * @param SymfonyRequest $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    private function authenticate(SymfonyRequest $request): ?array
    {
        // For admin routes, try admin provider first, then either jwt OR api_key (not both)
        $userData = $this->authManager->authenticateWithProvider('admin', $request);

        if (!$userData) {
            // If admin auth fails, try jwt
            $userData = $this->authManager->authenticateWithProvider('jwt', $request);

            // If jwt fails, try api_key as a last resort
            if (!$userData) {
                $userData = $this->authManager->authenticateWithProvider('api_key', $request);
            }
        }

        return $userData;
    }
}
