<?php

use Glueful\Http\Router;
use Glueful\Controllers\ResourceController;
use Glueful\Helpers\RequestHelper;
use Symfony\Component\HttpFoundation\Request;

// Get the container from the global app() helper
$container = app();

// Resource routes - no group needed since we want routes at root level
Router::get('/{resource}', function (array $params) use ($container) {
        $resourceController = $container->get(ResourceController::class);
        $request = Request::createFromGlobals();
        $queryParams = $request->query->all();
        return $resourceController->get($params, $queryParams);
}, requiresAuth: true);

Router::post('/{resource}', function (array $params) use ($container) {
        $resourceController = $container->get(ResourceController::class);
        $postData = RequestHelper::getRequestData();
        return $resourceController->post($params, $postData);
}, requiresAuth: true);

Router::put('/{resource}/{uuid}', function (array $params) use ($container) {
        $resourceController = $container->get(ResourceController::class);
        $putData = RequestHelper::getPutData();
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
            $deleteData = RequestHelper::getRequestData();
            return $resourceController->bulkDelete($params, $deleteData);
    }, requiresAuth: true);

    Router::put('/{resource}/bulk', function (array $params) use ($container) {
            $resourceController = $container->get(ResourceController::class);
            $updateData = RequestHelper::getRequestData();
            return $resourceController->bulkUpdate($params, $updateData);
    }, requiresAuth: true);
}
