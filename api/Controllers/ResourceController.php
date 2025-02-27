<?php
declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\{APIEngine, Http\Response};
use Glueful\Controllers\AuthController;
use Glueful\Permissions\{Permissions, Permission};
use Glueful\Validation\Validator;
use Glueful\DTOs\{UsernameDTO, EmailDTO, PasswordDTO, ListResourceRequestDTO};

class ResourceController {
    private AuthController $auth;
    private Permissions $permissions;

    public function __construct() {
        $this->auth = new AuthController();
        $this->permissions = new Permissions();
    }

    /**
     * Get resource list with pagination
     * 
     * @param array $params Route parameters
     * @param array $queryParams Query string parameters
     * @return mixed HTTP response
     */
    public function get(array $params, array $queryParams) {
        try {
            $this->auth->validateToken();
            $token = $_GET['token'] ?? null;
            
            if (!$this->permissions->hasPermission("api.{$params['resource']}", Permission::VIEW, $token)) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
            }
            
            // Validate the list request
            $listRequest = $this->validateListRequest($queryParams);
            $queryParams = array_merge($listRequest, [
                'fields' => $listRequest['fields'] ?? '*',
                'sort' => $listRequest['sort'] ?? 'created_at',
                'page' => $listRequest['page'] ?? 1,
                'per_page' => $listRequest['per_page'] ?? 25,
                'order' => $listRequest['order'] ?? 'desc'
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
    public function getSingle(array $params, array $queryParams) {
        try {
            $this->auth->validateToken();
            $token = $_GET['token'] ?? null;
            
            if (!$this->permissions->hasPermission("api.{$params['resource']}", Permission::VIEW, $token)) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
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
    public function post(array $params, array $postData) {
        try {
            $this->auth->validateToken();
            $token = $_GET['token'] ?? null;

            if (!$this->permissions->hasPermission("api.{$params['resource']}", Permission::SAVE, $token)) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
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
    public function put(array $params, array $putData) {
        try {
            $this->auth->validateToken();
            $token = $_GET['token'] ?? null;

            if (!$this->permissions->hasPermission("api.{$params['resource']}", Permission::EDIT, $token)) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
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
    public function delete(array $params) {
        try {
            $this->auth->validateToken();
            $token = $_GET['token'] ?? null;

            if (!$this->permissions->hasPermission("api.{$params['resource']}", Permission::DELETE, $token)) {
                return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
            }

            $result = APIEngine::saveData(
                $params['resource'],
                'delete',
                ['uuid' => $params['uuid'], 'status' => 'D']
            );
                    
            return Response::ok($result)->send();
            
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
}