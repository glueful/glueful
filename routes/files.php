<?php

use Glueful\Http\Router;
use Glueful\Controllers\FilesController;


$filesController = new FilesController();

 // File routes
Router::addRoute('GET', 'files/{uuid}', function() use ($filesController) {
    return $filesController->getFile();
});

Router::addRoute('POST', 'files', function() use ($filesController) {
    return $filesController->uploadFile();
});

Router::addRoute('DELETE', 'files/{uuid}', function($params) use ($filesController) {
    return $filesController->deleteFile($params);
});