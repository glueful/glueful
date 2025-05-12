<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\{APIEngine, Http\Response};
use Glueful\Auth\AuthBootstrap;
use Glueful\Auth\SessionCacheManager;
use Glueful\Permissions\Permission;
use Glueful\Permissions\PermissionManager;
use Glueful\Repository\RoleRepository;
use Glueful\Validation\Validator;
use Glueful\DTOs\{UsernameDTO, EmailDTO, PasswordDTO, ListResourceRequestDTO};
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

            // Extract token for permission check
            $token = $userData['token'] ?? $_GET['token'] ?? null;

            if (!$token) {
                return Response::error('No valid token found', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get session from token which contains user data
            $session = SessionCacheManager::getSession($token);
            if (!$session || !isset($session['user']['uuid'])) {
                return Response::error('Invalid session', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get user UUID from session
            $userUuid = $session['user']['uuid'];

            // Check if user has superuser role
            $roleRepo = new RoleRepository();
            if ($roleRepo->hasRole($userUuid, 'superuser')) {
                // Superuser has access to everything - skip permission check
            } else {
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

            // Extract token for permission check
            $token = $userData['token'] ?? $_GET['token'] ?? null;

            if (!$token) {
                return Response::error('No valid token found', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get session from token which contains user data
            $session = SessionCacheManager::getSession($token);
            if (!$session || !isset($session['user']['uuid'])) {
                return Response::error('Invalid session', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get user UUID from session
            $userUuid = $session['user']['uuid'];

            // Check if user has superuser role
            $roleRepo = new RoleRepository();
            if ($roleRepo->hasRole($userUuid, 'superuser')) {
                // Superuser has access to everything - skip permission check
            } else {
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

            // Extract token for permission check
            $token = $userData['token'] ?? $_GET['token'] ?? null;

            if (!$token) {
                return Response::error('No valid token found', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get session from token which contains user data
            $session = SessionCacheManager::getSession($token);
            if (!$session || !isset($session['user']['uuid'])) {
                return Response::error('Invalid session', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get user UUID from session
            $userUuid = $session['user']['uuid'];

            // Check if user has superuser role
            $roleRepo = new RoleRepository();
            if ($roleRepo->hasRole($userUuid, 'superuser')) {
                // Superuser has access to everything - skip permission check
            } else {
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

            // Extract token for permission check
            $token = $userData['token'] ?? $_GET['token'] ?? null;

            if (!$token) {
                return Response::error('No valid token found', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get session from token which contains user data
            $session = SessionCacheManager::getSession($token);
            if (!$session || !isset($session['user']['uuid'])) {
                return Response::error('Invalid session', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get user UUID from session
            $userUuid = $session['user']['uuid'];

            // Check if user has superuser role
            $roleRepo = new RoleRepository();
            if ($roleRepo->hasRole($userUuid, 'superuser')) {
                // Superuser has access to everything - skip permission check
            } else {
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

            // Extract token for permission check
            $token = $userData['token'] ?? $_GET['token'] ?? null;

            if (!$token) {
                return Response::error('No valid token found', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get session from token which contains user data
            $session = SessionCacheManager::getSession($token);
            if (!$session || !isset($session['user']['uuid'])) {
                return Response::error('Invalid session', Response::HTTP_UNAUTHORIZED)->send();
            }

            // Get user UUID from session
            $userUuid = $session['user']['uuid'];

            // Check if user has superuser role
            $roleRepo = new RoleRepository();
            if ($roleRepo->hasRole($userUuid, 'superuser')) {
                // Superuser has access to everything - skip permission check
            } else {
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
     * Validate list request parameters
     *
     * @param array $queryParams Query parameters to validate
     * @return array Validated parameters
     * @throws \Exception If validation fails
     */
    private function validateListRequest(array $queryParams): array
    {
        $validator = new Validator();
        $listRequest = new ListResourceRequestDTO();
        $listRequest->fields = $queryParams['fields'] ?? null;
        $listRequest->sort = $queryParams['sort'] ?? null;
        $listRequest->page = $queryParams['page'] ?? null;
        $listRequest->per_page = $queryParams['per_page'] ?? null;
        $listRequest->order = $queryParams['order'] ?? null;

        if (!$validator->validate($listRequest)) {
            throw new \Exception(json_encode($validator->errors()), 400);
        }

        return (array)$listRequest;
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
}
