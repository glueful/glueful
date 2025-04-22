<?php

use Glueful\Http\Router;
use Glueful\Controllers\FilesController;


$filesController = new FilesController();

// File routes
Router::group('/files', function() use ($filesController) {
    Router::get('/{uuid}', function($params) use ($filesController) {
        return $filesController->getFile($params);
    });

    Router::post('/', function() use ($filesController) {
        return $filesController->uploadFile();
    });

    Router::delete('/{uuid}', function($params) use ($filesController) {
        return $filesController->deleteFile($params);
    });
}, requiresAuth: true);