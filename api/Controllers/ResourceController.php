<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\{APIEngine, Http\Response};
use Glueful\Auth\AuthBootstrap;
use Glueful\Permissions\Permission;
use Glueful\Permissions\PermissionManager;
use Glueful\Repository\RoleRepository;
use Symfony\Component\HttpFoundation\Request;

class ResourceController
{
    private $authManager;

    public function __construct()
    {
        // Initialize auth system
        AuthBootstrap::initialize();
        $this->authManager = AuthBootstrap::getManager();

        // Initialize the permission manager
        PermissionManager::initialize();
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
            $roleRepo = new RoleRepository();
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

            $queryParams = array_merge($queryParams, [
                'fields' => $queryParams['fields'] ?? '*',
                'sort' => $queryParams['sort'] ?? 'created_at',
                'page' => $queryParams['page'] ?? 1,
                'per_page' => $queryParams['per_page'] ?? 25,
                'order' => $queryParams['order'] ?? 'desc'
            ]);

            $result = APIEngine::getData($params['resource'], 'list', $queryParams);
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
            $roleRepo = new RoleRepository();
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

            $queryParams = array_merge($queryParams, [
                'fields' => $queryParams['fields'] ?? '*',
                'uuid' => $params['uuid'],
                'paginate' => false
            ]);

            $result = APIEngine::getData($params['resource'], 'list', $queryParams);
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
            $roleRepo = new RoleRepository();
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

            $result = APIEngine::saveData(
                $params['resource'],        // resource name
                'save',                     // action
                $postData                   // data to save
            );

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
            $roleRepo = new RoleRepository();
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

            // Direct API call without legacy conversion
            $result = APIEngine::saveData(
                $params['resource'],        // resource name
                'update',                   // action
                $putData                    // data to save
            );

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
            $roleRepo = new RoleRepository();
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

            $result = APIEngine::saveData(
                $params['resource'],
                'delete',
                ['uuid' => $params['uuid'], 'status' => 'D']
            );

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
}
