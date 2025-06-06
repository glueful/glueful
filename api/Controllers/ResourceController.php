<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Http\Response;
use Glueful\Auth\PasswordHasher;
use Glueful\Repository\RepositoryFactory;
use Glueful\Interfaces\Permission\PermissionStandards;
use Symfony\Component\HttpFoundation\Request;

class ResourceController extends BaseController
{
    private RepositoryFactory $repositoryFactory;

    public function __construct(?RepositoryFactory $repositoryFactory = null)
    {
        parent::__construct();
        $this->repositoryFactory = $repositoryFactory ?? new RepositoryFactory();
    }


    /**
     * Get resource list with pagination
     *
     * @param Request $request HTTP request object
     * @return Response HTTP response
     */
    public function get(Request $request): Response
    {
        try {
            // Authenticate and get user data
            $userData = $this->requireAuthentication($request);

            // Extract resource name from request parameters
            $resourceName = $request->attributes->get('resource', 'unknown');

            // Check permission to view this specific resource
            $this->checkPermission($request, PermissionStandards::ACTION_VIEW, PermissionStandards::CATEGORY_API, [
                'action' => 'view_resource',
                'resource_type' => $resourceName,
                'endpoint' => "/{$resourceName}"
            ]);

            // Parse query parameters using BaseController helpers
            $queryParams = $this->getQueryParams($request);
            $pagination = $this->parsePaginationParams($queryParams, 25, 100);
            $sorting = $this->parseSortParams($queryParams, 'created_at', 'desc');
            $conditions = $this->parseFilterConditions($queryParams);
            $fields = $this->parseSelectFields($queryParams['fields'] ?? '');

            // Get repository and paginate results
            $repository = $this->repositoryFactory->getRepository($resourceName);
            $result = $repository->paginate(
                $pagination['page'],
                $pagination['per_page'],
                $conditions,
                $sorting['order_by'],
                $fields
            );

            return $this->successResponse($result, 'Resources retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieve resources');
        }
    }

    /**
     * Get single resource by UUID
     *
     * @param Request $request HTTP request object
     * @return Response HTTP response
     */
    public function getSingle(Request $request): Response
    {
        try {
            // Authenticate and get user data
            $userData = $this->requireAuthentication($request);
            $userUuid = $this->getUserUuid($userData);

            // Extract resource name and UUID from request parameters
            $resourceName = $request->attributes->get('resource', 'unknown');
            $resourceUuid = $request->attributes->get('uuid');

            if (!$resourceUuid) {
                return $this->validationErrorResponse('Resource UUID is required');
            }

            // Check permission to view this specific resource
            $this->checkPermission($request, PermissionStandards::ACTION_VIEW, PermissionStandards::CATEGORY_API, [
                'action' => 'view_single_resource',
                'resource_type' => $resourceName,
                'resource_uuid' => $resourceUuid,
                'endpoint' => "/{$resourceName}/{$resourceUuid}"
            ]);

            // Get repository and find single record
            $repository = $this->repositoryFactory->getRepository($resourceName);
            $result = $repository->find($resourceUuid);

            if (!$result) {
                return $this->notFoundResponse('Resource not found');
            }

            return $this->successResponse($result, 'Resource retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'retrieve resource');
        }
    }

    /**
     * Create new resource
     *
     * @param Request $request HTTP request object
     * @return Response HTTP response
     */
    public function post(Request $request): Response
    {
        try {
            // Authenticate and get user data
            $userData = $this->requireAuthentication($request);
            $userUuid = $this->getUserUuid($userData);

            // Extract resource name from request parameters
            $resourceName = $request->attributes->get('resource', 'unknown');

            // Check permission to create this specific resource
            $this->checkPermission($request, PermissionStandards::ACTION_CREATE, PermissionStandards::CATEGORY_API, [
                'action' => 'create_resource',
                'resource_type' => $resourceName,
                'endpoint' => "/{$resourceName}"
            ]);

            // Get request data using BaseController method
            $postData = $this->getRequestData($request);

            if (empty($postData)) {
                return $this->validationErrorResponse('No data provided');
            }

            // Hash password if present
            $passwordHasher = new PasswordHasher();
            if (isset($postData['password'])) {
                $postData['password'] = $passwordHasher->hash($postData['password']);
            }

            // Get repository and create record
            $repository = $this->repositoryFactory->getRepository($resourceName);
            $uuid = $repository->create($postData);

            $result = [
                'uuid' => $uuid,
                'success' => true,
                'message' => 'Resource created successfully'
            ];

            return $this->successResponse($result, 'Resource created successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'create resource');
        }
    }

    /**
     * Update existing resource
     *
     * @param Request $request HTTP request object
     * @return Response HTTP response
     */
    public function put(Request $request): Response
    {
        try {
            // Authenticate and get user data
            $userData = $this->requireAuthentication($request);
            $userUuid = $this->getUserUuid($userData);

            // Extract resource name and UUID from request parameters
            $resourceName = $request->attributes->get('resource', 'unknown');
            $resourceUuid = $request->attributes->get('uuid');

            if (!$resourceUuid) {
                return $this->validationErrorResponse('Resource UUID is required');
            }

            // Check permission to edit this specific resource
            $this->checkPermission($request, PermissionStandards::ACTION_EDIT, PermissionStandards::CATEGORY_API, [
                'action' => 'update_resource',
                'resource_type' => $resourceName,
                'resource_uuid' => $resourceUuid,
                'endpoint' => "/{$resourceName}/{$resourceUuid}"
            ]);

            // Get request data using BaseController method
            $putData = $this->getRequestData($request);

            // Extract data from nested structure if present (for compatibility)
            $updateData = $putData['data'] ?? $putData;
            unset($updateData['uuid']); // Remove UUID from update data

            if (empty($updateData)) {
                return $this->validationErrorResponse('No update data provided');
            }

            // Hash password if present
            $passwordHasher = new PasswordHasher();
            if (isset($updateData['password'])) {
                $updateData['password'] = $passwordHasher->hash($updateData['password']);
            }

            // Get repository and update record
            $repository = $this->repositoryFactory->getRepository($resourceName);
            $success = $repository->update($resourceUuid, $updateData);

            if (!$success) {
                return $this->notFoundResponse('Resource not found or update failed');
            }

            $result = [
                'affected' => 1,
                'success' => true,
                'message' => 'Resource updated successfully'
            ];

            return $this->successResponse($result, 'Resource updated successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'update resource');
        }
    }

    /**
     * Delete resource
     *
     * @param Request $request HTTP request object
     * @return Response HTTP response
     */
    public function delete(Request $request): Response
    {
        try {
            // Authenticate and get user data
            $userData = $this->requireAuthentication($request);
            $userUuid = $this->getUserUuid($userData);

            // Extract resource name and UUID from request parameters
            $resourceName = $request->attributes->get('resource', 'unknown');
            $resourceUuid = $request->attributes->get('uuid');

            if (!$resourceUuid) {
                return $this->validationErrorResponse('Resource UUID is required');
            }

            // Check permission to delete this specific resource
            $this->checkPermission($request, PermissionStandards::ACTION_DELETE, PermissionStandards::CATEGORY_API, [
                'action' => 'delete_resource',
                'resource_type' => $resourceName,
                'resource_uuid' => $resourceUuid,
                'endpoint' => "/{$resourceName}/{$resourceUuid}"
            ]);

            // Get repository and delete record
            $repository = $this->repositoryFactory->getRepository($resourceName);
            $success = $repository->delete($resourceUuid);

            if (!$success) {
                return $this->notFoundResponse('Resource not found or delete failed');
            }

            $result = [
                'affected' => 1,
                'success' => true,
                'message' => 'Resource deleted successfully'
            ];

            return $this->successResponse($result, 'Resource deleted successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'delete resource');
        }
    }
}
