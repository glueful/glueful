<?php

use Glueful\Http\Router;
use Glueful\Controllers\FilesController;


$filesController = new FilesController();

 // File routes
 // TODO: Add middleware to check if user is authenticated (requiresAuth in Router)
Router::get('files/{uuid}', function() use ($filesController) {
    return $filesController->getFile();
});

Router::post('files', function() use ($filesController) {
    return $filesController->uploadFile();
});

Router::delete('files/{uuid}', function($params) use ($filesController) {
    return $filesController->deleteFile($params);
});