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

    public function get(array $params, array $queryParams): Response {
        $this->auth->validateToken();
        $token = $GET['token'] ?? null;
        if (!$this->permissions->hasPermission("api.{$params['resource']}", Permission::VIEW, $token)) {
            return Response::error('Forbidden', Response::HTTP_FORBIDDEN);
        }
        // Validate the list request
        $listRequest = $this->validateListRequest($queryParams);
        $queryParams = array_merge( $listRequest, [
            'fields' => $listRequest['fields'] ?? '*',
            'sort' => $listRequest['sort'] ?? 'created_at',
            'page' => $listRequest['page'] ?? 1,
            'per_page' => $listRequest['per_page'] ?? 25,
            'order' => $listRequest['order'] ?? 'desc'
        ]);

        $result = APIEngine::getData($params['resource'], 'list', $queryParams);
        return Response::ok($result);
    }

    public function getSingle(array $params, array $queryParams): Response {
        $this->auth->validateToken();
        $token = $GET['token'] ?? null;
        if (!$this->permissions->hasPermission("api.{$params['resource']}", Permission::VIEW, $token)) {
            return Response::error('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $queryParams = array_merge($queryParams, [
            'fields' => $queryParams['fields'] ?? '*',
            'uuid' => $queryParams['uuid'],
            'paginate' => false
        ]);

        $queryParams = array_merge($_GET, [
            'fields' => $_GET['fields'] ?? '*',
            'uuid' => $params['id'],
            'paginate' => false
        ]);
        
        $result = APIEngine::getData($params['resource'], 'list', $queryParams);
        return Response::ok($result);
    }
    
    public function post(array $params, array $postDtata): Response {
        $this->auth->validateToken();
        $token = $GET['token'] ?? null;

        if (!Permissions::hasPermission("api.{$params['resource']}", Permission::SAVE, $token)) {
            return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
        }

        $result = APIEngine::saveData(
            $params['resource'],        // resource name
            'save',                     // action
            $postDtata                  // data to save
        );
                
        return Response::ok($result);
    }

    public function put(array $params, array $putDtata): Response{
        $this->auth->validateToken();
        $token = $GET['token'] ?? null;

        if (!Permissions::hasPermission("api.{$params['resource']}", Permission::EDIT, $token)) {
            return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
        }

        // Direct API call without legacy conversion
        $result = APIEngine::saveData(
            $params['resource'],        // resource name
            'update',                     // action
            $putDtata                  // data to save
        );
                
        return Response::ok($result);
    }

    public function delete(array $params): Response {
        $this->auth->validateToken();
        $token = $GET['token'] ?? null;

        if (!Permissions::hasPermission("api.{$params['resource']}", Permission::DELETE, $token)) {
            return Response::error('Forbidden', Response::HTTP_FORBIDDEN)->send();
        }

        $result = APIEngine::saveData(
            $params['resource'],
            'delete',
            ['uuid' => $params['uuid'], 'status' => 'D']
        );
                
        return Response::ok($result);
    }


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
            throw new \Exception(json_encode( $validator->errors()), 400);
        }
        return (array)$listRequest;
    }
}