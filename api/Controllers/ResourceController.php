<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Auth\AuthBootstrap;
use Glueful\Permissions\Permission;
use Glueful\Permissions\PermissionManager;
use Glueful\Repository\RepositoryFactory;
use Symfony\Component\HttpFoundation\Request;

class ResourceController
{
    private $authManager;
    private RepositoryFactory $repositoryFactory;

    public function __construct(?RepositoryFactory $repositoryFactory = null)
    {
        // Initialize auth system
        AuthBootstrap::initialize();
        $this->authManager = AuthBootstrap::getManager();

        // Initialize the permission manager
        PermissionManager::initialize();

        // Initialize repository factory
        $this->repositoryFactory = $repositoryFactory ?? new RepositoryFactory();
    }

    /**
     * Get resource list with pagination
     *
     * @param array $params Route parameters
     * @param array $queryParams Query string parameters
     * @return mixed HTTP response
     */
    public function get(array $params, array $queryParams)
    {
        try {
            // Authenticate using the new abstraction layer
            $request = Request::createFromGlobals();
            $userData = $this->authenticate($request);

            if (!$userData) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Extract user UUID directly from authenticated data
            $userUuid = $this->getUserUuid($userData);

            if (!$userUuid) {
                return Response::error('Invalid user data', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
            }

            // Check if user has superuser role
            $roleRepo = $this->repositoryFactory->roles();
            if ($roleRepo->hasRole($userUuid, 'superuser')) {
                // Superuser has access to everything - skip permission check
            } else {
                // For permission check, we need the token
                $token = $this->extractToken($request);
                if (!$token) {
                    return Response::error('No valid token found', Response::HTTP_UNAUTHORIZED)->send();
                }

                // Check permissions using the PermissionManager for non-superusers
                if (!PermissionManager::can($params['resource'], Permission::VIEW->value, $token)) {
                    return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
                }
            }

            // Parse query parameters for repository
            $page = max(1, (int)($queryParams['page'] ?? 1));
            $perPage = min(100, max(1, (int)($queryParams['per_page'] ?? 25)));
            $sort = $queryParams['sort'] ?? 'created_at';
            $order = strtolower($queryParams['order'] ?? 'desc');
            $order = in_array($order, ['asc', 'desc']) ? $order : 'desc';
            $fields = $this->parseFields($queryParams['fields'] ?? '');

            // Build conditions and order
            $conditions = $this->parseConditions($queryParams);
            $orderBy = [$sort => $order];

            // Get repository and paginate results
            $repository = $this->repositoryFactory->getRepository($params['resource']);
            $result = $repository->paginate($page, $perPage, $conditions, $orderBy, $fields);
            return Response::ok($result)->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to retrieve data: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Get single resource by UUID
     *
     * @param array $params Route parameters
     * @param array $queryParams Query string parameters
     * @return mixed HTTP response
     */
    public function getSingle(array $params, array $queryParams)
    {
        try {
            // Authenticate using the new abstraction layer
            $request = Request::createFromGlobals();
            $userData = $this->authenticate($request);

            if (!$userData) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Extract user UUID directly from authenticated data
            $userUuid = $this->getUserUuid($userData);

            if (!$userUuid) {
                return Response::error('Invalid user data', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
            }

            // Check if user has superuser role
            $roleRepo = $this->repositoryFactory->roles();
            if ($roleRepo->hasRole($userUuid, 'superuser')) {
                // Superuser has access to everything - skip permission check
            } else {
                // For permission check, we need the token
                $token = $this->extractToken($request);
                if (!$token) {
                    return Response::error('No valid token found', Response::HTTP_UNAUTHORIZED)->send();
                }

                // Check permissions using the PermissionManager for non-superusers
                if (!PermissionManager::can($params['resource'], Permission::VIEW->value, $token)) {
                    return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
                }
            }

            // Get repository and find single record
            $repository = $this->repositoryFactory->getRepository($params['resource']);
            $result = $repository->find($params['uuid']);

            if (!$result) {
                return Response::error('Record not found', Response::HTTP_NOT_FOUND)->send();
            }
            return Response::ok($result)->send();
        } catch (\Exception $e) {
            return Response::error(
                'Failed to retrieve data: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Create new resource
     *
     * @param array $params Route parameters
     * @param array $postData POST data
     * @return mixed HTTP response
     */
    public function post(array $params, array $postData)
    {
        try {
            // Authenticate using the new abstraction layer
            $request = Request::createFromGlobals();
            $userData = $this->authenticate($request);

            if (!$userData) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Extract user UUID directly from authenticated data
            $userUuid = $this->getUserUuid($userData);

            if (!$userUuid) {
                return Response::error('Invalid user data', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
            }

            // Check if user has superuser role
            $roleRepo = $this->repositoryFactory->roles();
            if ($roleRepo->hasRole($userUuid, 'superuser')) {
                // Superuser has access to everything - skip permission check
            } else {
                // For permission check, we need the token
                $token = $this->extractToken($request);
                if (!$token) {
                    return Response::error('No valid token found', Response::HTTP_UNAUTHORIZED)->send();
                }

                // Check permissions using the PermissionManager for non-superusers
                if (!PermissionManager::can($params['resource'], Permission::SAVE->value, $token)) {
                    return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
                }
            }

            // Get repository and create record
            $repository = $this->repositoryFactory->getRepository($params['resource']);
            $uuid = $repository->create($postData);

            $result = [
                'uuid' => $uuid,
                'success' => true,
                'message' => 'Record created successfully'
            ];

            return Response::ok($result)->send();
        } catch (\Exception $e) {
            return Response::error(
                'Save failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Update existing resource
     *
     * @param array $params Route parameters
     * @param array $putData PUT data
     * @return mixed HTTP response
     */
    public function put(array $params, array $putData)
    {
        try {
            // Authenticate using the new abstraction layer
            $request = Request::createFromGlobals();
            $userData = $this->authenticate($request);

            if (!$userData) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Extract user UUID directly from authenticated data
            $userUuid = $this->getUserUuid($userData);

            if (!$userUuid) {
                return Response::error('Invalid user data', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
            }

            // Check if user has superuser role
            $roleRepo = $this->repositoryFactory->roles();
            if ($roleRepo->hasRole($userUuid, 'superuser')) {
                // Superuser has access to everything - skip permission check
            } else {
                // For permission check, we need the token
                $token = $this->extractToken($request);
                if (!$token) {
                    return Response::error('No valid token found', Response::HTTP_UNAUTHORIZED)->send();
                }

                // Check permissions using the PermissionManager for non-superusers
                if (!PermissionManager::can($params['resource'], Permission::EDIT->value, $token)) {
                    return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
                }
            }

            // Get repository and update record
            $repository = $this->repositoryFactory->getRepository($params['resource']);

            // Extract data from nested structure if present (for compatibility)
            $updateData = $putData['data'] ?? $putData;
            unset($updateData['uuid']); // Remove UUID from update data


            $success = $repository->update($params['uuid'], $updateData);

            if (!$success) {
                return Response::error('Record not found or update failed', Response::HTTP_NOT_FOUND)->send();
            }

            $result = [
                'affected' => 1,
                'success' => true,
                'message' => 'Record updated successfully'
            ];

            return Response::ok($result)->send();
        } catch (\Exception $e) {
            return Response::error(
                'Update failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Delete resource
     *
     * @param array $params Route parameters
     * @return mixed HTTP response
     */
    public function delete(array $params)
    {
        try {
            // Authenticate using the new abstraction layer
            $request = Request::createFromGlobals();
            $userData = $this->authenticate($request);

            if (!$userData) {
                return Response::error('Unauthorized', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Extract user UUID directly from authenticated data
            $userUuid = $this->getUserUuid($userData);

            if (!$userUuid) {
                return Response::error('Invalid user data', Response::HTTP_INTERNAL_SERVER_ERROR)->send();
            }

            // Check if user has superuser role
            $roleRepo = $this->repositoryFactory->roles();
            if ($roleRepo->hasRole($userUuid, 'superuser')) {
                // Superuser has access to everything - skip permission check
            } else {
                // For permission check, we need the token
                $token = $this->extractToken($request);
                if (!$token) {
                    return Response::error('No valid token found', Response::HTTP_UNAUTHORIZED)->send();
                }

                // Check permissions using the PermissionManager for non-superusers
                if (!PermissionManager::can($params['resource'], Permission::DELETE->value, $token)) {
                    return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
                }
            }

            // Get repository and delete record
            $repository = $this->repositoryFactory->getRepository($params['resource']);
            $success = $repository->delete($params['uuid']);

            if (!$success) {
                return Response::error('Record not found or delete failed', Response::HTTP_NOT_FOUND)->send();
            }

            $result = [
                'affected' => 1,
                'success' => true,
                'message' => 'Record deleted successfully'
            ];

            return Response::ok($result, 'Resource deleted successfully')->send();
        } catch (\Exception $e) {
            return Response::error(
                'Delete failed: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            )->send();
        }
    }

    /**
     * Authenticate a request using multiple authentication methods
     *
     * @param Request $request The HTTP request to authenticate
     * @return array|null User data if authenticated, null otherwise
     */
    private function authenticate(Request $request): ?array
    {
        // Try to authenticate with all available methods
        return $this->authManager->authenticateWithProviders(['jwt', 'api_key'], $request);
    }

    /**
     * Extract user UUID from authentication data
     *
     * Handles different authentication response formats
     *
     * @param array $authData Authentication data
     * @return string|null User UUID
     */
    private function getUserUuid(array $authData): ?string
    {
        // For JWT auth, the returned data is the session record
        // Check for user_uuid field first (auth_sessions table)
        if (isset($authData['user_uuid'])) {
            return $authData['user_uuid'];
        }

        // Direct UUID in auth data
        if (isset($authData['uuid'])) {
            return $authData['uuid'];
        }

        // UUID nested in user object
        if (isset($authData['user']['uuid'])) {
            return $authData['user']['uuid'];
        }

        // UUID in nested user data (some providers return this structure)
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

    /**
     * Parse query conditions from request parameters
     */
    private function parseConditions(array $queryParams): array
    {
        $conditions = [];

        // Add any filter conditions from query params
        foreach ($queryParams as $key => $value) {
            // Skip pagination and sorting parameters
            if (in_array($key, ['page', 'per_page', 'sort', 'order', 'fields'])) {
                continue;
            }

            // Simple equality conditions for now
            $conditions[$key] = $value;
        }

        return $conditions;
    }

    /**
     * Parse fields to select from request parameters
     */
    private function parseFields(string $fields): array
    {
        if (empty($fields) || $fields === '*') {
            return [];
        }

        return array_map('trim', explode(',', $fields));
    }
}
