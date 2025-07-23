<?php

/**
 * Public static routes
 *
 * These routes serve static content like documentation
 */

declare(strict_types=1);

use Glueful\Http\Router;

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

// Serve static documentation files
Router::static('/docs', dirname(__DIR__) . '/docs', false, [
    'indexFile' => 'index.html',
    'allowedExtensions' => ['html', 'md', 'json', 'css', 'js', 'png', 'jpg', 'gif', 'svg']
]);
