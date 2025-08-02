<?php

/**
 * Public static routes
 *
 * These routes serve static content like documentation
 */

declare(strict_types=1);

use Glueful\Http\Router;
// Setup wizard routes
use Glueful\Setup\Controllers\SetupController;

// Serve swagger.json at root API level for documentation compatibility
Router::get('/swagger.json', function () {
    $swaggerPath = dirname(__DIR__) . '/docs/swagger.json';
    if (file_exists($swaggerPath)) {
        $content = file_get_contents($swaggerPath);
        return new \Symfony\Component\HttpFoundation\Response($content, 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0'
        ]);
    }
    return new \Symfony\Component\HttpFoundation\JsonResponse([
        'error' => 'Swagger specification not found'
    ], 404);
});

Router::get('/logo_full.svg', function () {
    $logoPath = dirname(__DIR__) . '/docs/logo_full.svg';
    if (file_exists($logoPath)) {
        $content = file_get_contents($logoPath);
        return new \Symfony\Component\HttpFoundation\Response($content, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=3600'
        ]);
    }
    return new \Symfony\Component\HttpFoundation\Response('Logo not found', 404);
});

// Serve static documentation files
Router::static('/docs', dirname(__DIR__) . '/docs', false, [
    'indexFile' => 'index.html',
    'allowedExtensions' => ['html', 'md', 'json', 'css', 'js', 'png', 'jpg', 'gif', 'svg']
]);




Router::get('/setup/setup.css', function () {
    $cssPath = dirname(__DIR__) . '/setup/setup.css';
    if (file_exists($cssPath)) {
        $content = file_get_contents($cssPath);
        return new \Symfony\Component\HttpFoundation\Response($content, 200, [
            'Content-Type' => 'text/css',
            // 'Cache-Control' => 'public, max-age=3600'
        ]);
    }
    return new \Symfony\Component\HttpFoundation\Response('CSS not found', 404);
});

Router::get('/bg_shape.svg', function () {
    $logoPath = dirname(__DIR__) . '/setup/bg_shape.svg';
    if (file_exists($logoPath)) {
        $content = file_get_contents($logoPath);
        return new \Symfony\Component\HttpFoundation\Response($content, 200, [
            'Content-Type' => 'image/svg+xml',
            // 'Cache-Control' => 'public, max-age=3600'
        ]);
    }
    return new \Symfony\Component\HttpFoundation\Response('Logo not found', 404);
});

Router::get('/setup/setup.js', function () {
    $jsPath = dirname(__DIR__) . '/setup/setup.js';
    if (file_exists($jsPath)) {
        $content = file_get_contents($jsPath);
        return new \Symfony\Component\HttpFoundation\Response($content, 200, [
            'Content-Type' => 'application/javascript',
            'Cache-Control' => 'public, max-age=3600'
        ]);
    }
    return new \Symfony\Component\HttpFoundation\Response('JS not found', 404);
});

Router::get('/setup', function (array $params) {
    $step = '/welcome';
    $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    $setupController = new SetupController();
    return $setupController->index($request, $step);
});

Router::get('/setup/{step}', function (array $params) {
    $step = $params['step'] ?? '/welcome';
    $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    $setupController = new SetupController();
    return $setupController->index($request, $step);
});

// Serve setup static files
Router::static('/setup', dirname(__DIR__) . '/setup', false, [
    'allowedExtensions' => ['css', 'js', 'png', 'jpg', 'gif', 'svg', 'html',]
]);
