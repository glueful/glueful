<?php

use Glueful\Http\Router;
use Glueful\Controllers\ResourceController;
use Glueful\Helpers\Request;

$resourceController = new ResourceController();
$request = new Request();

// Resource routes
Router::group('/', function() use ($resourceController, $request) {
    Router::get('/{resource}', function(array $params) use ($resourceController, $request) {
        $queryParams = $request->getQueryParams();
        return $resourceController->get($params, $queryParams);
    });
    Router::post('/{resource}', function(array $params) use ($resourceController, $request) {
        $postData = Request::getPostData();
        return $resourceController->post($params, $postData);
    });

    Router::put('/{resource}/{uuid}', function(array $params) use ($resourceController, $request) {
        $putData = Request::getPostData();
        $putData['uuid'] = $params['uuid'];
        return $resourceController->put($params, $putData);
    });

    Router::delete('/{resource}/{uuid}', function(array $params) use ($resourceController, $request) {
        return $resourceController->delete($params);
    });
}, requiresAuth: true);