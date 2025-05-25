<?php

// Load composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load .env file
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Load global helper functions (env, config, etc.)
require_once __DIR__ . '/helpers.php';

// Validate security configuration in production
if (env('APP_ENV') === 'production') {
    \Glueful\Security\SecurityManager::validateProductionConfig();
}

// Glueful\ExceptionHandler::register();

// Initialize Cache Engine
Glueful\Helpers\Utils::initializeCacheEngine('glueful:');

// Initialize API versioning
$apiVersion = config('app.api_version', 'v1');
\Glueful\Http\Router::setVersion($apiVersion);

// Enable cache tagging and invalidation services (if cache is enabled)
if (\Glueful\Cache\CacheEngine::isEnabled()) {
    \Glueful\Cache\CacheTaggingService::enable();
    \Glueful\Cache\CacheInvalidationService::enable();
    \Glueful\Cache\CacheInvalidationService::warmupPatterns();
}

// Enable development query monitoring in development environment
if (env('APP_ENV') === 'development' && env('ENABLE_QUERY_MONITORING', true)) {
    \Glueful\Database\DevelopmentQueryMonitor::enable();
}

// Validate database connection on startup (if enabled)
if (env('DB_STARTUP_VALIDATION', true) && !env('SKIP_DB_VALIDATION', false)) {
    \Glueful\Database\ConnectionValidator::validateOnStartup(
        throwOnFailure: env('DB_STARTUP_STRICT', false)
    );
}

// Initialize API Engine
Glueful\APIEngine::initialize();
