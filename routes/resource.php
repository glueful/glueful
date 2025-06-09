<?php

use Glueful\Http\Router;
use Glueful\Controllers\ResourceController;
use Glueful\Helpers\Request;

// Get the container from the global app() helper
$container = app();

// Resource routes - no group needed since we want routes at root level
Router::get('/{resource}', function (array $params) use ($container) {
        $resourceController = $container->get(ResourceController::class);
        $request = new Request();
        $queryParams = $request->getQueryParams();
        return $resourceController->get($params, $queryParams);
}, requiresAuth: true);

Router::post('/{resource}', function (array $params) use ($container) {
        $resourceController = $container->get(ResourceController::class);
        $postData = Request::getPostData();
        return $resourceController->post($params, $postData);
}, requiresAuth: true);

Router::put('/{resource}/{uuid}', function (array $params) use ($container) {
        $resourceController = $container->get(ResourceController::class);
        $putData = Request::getPostData();
        $putData['uuid'] = $params['uuid'];
        return $resourceController->put($params, $putData);
}, requiresAuth: true);

Router::delete('/{resource}/{uuid}', function (array $params) use ($container) {
        $resourceController = $container->get(ResourceController::class);
        return $resourceController->delete($params);
}, requiresAuth: true);

// Bulk operation routes (only if enabled in configuration)
if (config('resource.security.bulk_operations', false)) {
    Router::delete('/{resource}/bulk', function (array $params) use ($container) {
            $resourceController = $container->get(ResourceController::class);
            $deleteData = Request::getPostData();
            return $resourceController->bulkDelete($params, $deleteData);
    }, requiresAuth: true);

    Router::put('/{resource}/bulk', function (array $params) use ($container) {
            $resourceController = $container->get(ResourceController::class);
            $updateData = Request::getPostData();
            return $resourceController->bulkUpdate($params, $updateData);
    }, requiresAuth: true);
}
