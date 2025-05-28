<?php

use Glueful\Http\Router;
use Glueful\Controllers\ResourceController;
use Glueful\Helpers\Request;

// Get the container from the global app() helper
$container = app();

// Resource routes
Router::group('/', function () use ($container) {
    Router::get('/{resource}', function (array $params) use ($container) {
        $resourceController = $container->get(ResourceController::class);
        $request = new Request();
        $queryParams = $request->getQueryParams();
        return $resourceController->get($params, $queryParams);
    });
    Router::post('/{resource}', function (array $params) use ($container) {
        $resourceController = $container->get(ResourceController::class);
        $postData = Request::getPostData();
        return $resourceController->post($params, $postData);
    });

    Router::put('/{resource}/{uuid}', function (array $params) use ($container) {
        $resourceController = $container->get(ResourceController::class);
        $putData = Request::getPostData();
        $putData['uuid'] = $params['uuid'];
        return $resourceController->put($params, $putData);
    });

    Router::delete('/{resource}/{uuid}', function (array $params) use ($container) {
        $resourceController = $container->get(ResourceController::class);
        return $resourceController->delete($params);
    });
}, requiresAuth: true);
