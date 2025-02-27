<?php

use Glueful\Http\Router;
use Glueful\Controllers\ResourceController;
use Glueful\Helpers\Request;

$resourceController = new ResourceController();
$request = new Request();

 // Resource routes
 Router::addRoute('GET', '{resource}', function($params) use ($resourceController, $request) {
    $queryParams = $request->getQueryParams();
    return $resourceController->get($params, $queryParams);
});

// Get single resource by UUID
Router::addRoute('GET', '{resource}/{uuid}', function($params) use ($resourceController, $request) {
    $queryParams = $request->getQueryParams();
    return $resourceController->getSingle($params, $queryParams);
});

Router::addRoute('POST', '{resource}', function($params) use ($resourceController) {
    $postData = Request::getPostData();
    return $resourceController->post($params, $postData);
});

// PUT Route (Update)
Router::addRoute('PUT', '{resource}/{uuid}', function($params) use ($resourceController) {
    $putData = Request::getPostData();
    // Add UUID to data
    $putData['id'] = $params['uuid'];
    return $resourceController->put($params, $putData);
});

// DELETE Route
Router::addRoute('DELETE', '{resource}/{uuid}', function($params) use ($resourceController) {
    return $resourceController->delete($params);
});