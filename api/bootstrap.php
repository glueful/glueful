<?php

// Load composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load .env file
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Load global helper functions (env, config, etc.)
require_once __DIR__ . '/helpers.php';

// Initialize Dependency Injection Container
$container = \Glueful\DI\ContainerBootstrap::initialize();

// Make container globally available
$GLOBALS['container'] = $container;

// Validate security configuration in production
if (env('APP_ENV') === 'production') {
    \Glueful\Security\SecurityManager::validateProductionEnvironment();
}

Glueful\Exceptions\ExceptionHandler::register();

// Initialize Cache Driver
Glueful\Helpers\Utils::initializeCacheDriver();

// Initialize API versioning
$apiVersion = config('app.api_version', 'v1');
\Glueful\Http\Router::setVersion($apiVersion);

// Enable cache tagging and invalidation services
// Cache services will handle their own initialization via DI
\Glueful\Cache\CacheTaggingService::enable();
\Glueful\Cache\CacheInvalidationService::enable();
\Glueful\Cache\CacheInvalidationService::warmupPatterns();

// Enable development query monitoring in development environment
if (env('APP_ENV') === 'development' && env('ENABLE_QUERY_MONITORING', true)) {
    \Glueful\Database\DevelopmentQueryMonitor::enable();
}

// Initialize VarDumper for development debugging
if (env('APP_ENV') === 'development' && env('APP_DEBUG', false)) {
    if (class_exists(\Symfony\Component\VarDumper\VarDumper::class)) {
        \Symfony\Component\VarDumper\VarDumper::setHandler(function ($var) {
            $cloner = new \Symfony\Component\VarDumper\Cloner\VarCloner();
            $dumper = 'cli' === PHP_SAPI
                ? new \Symfony\Component\VarDumper\Dumper\CliDumper()
                : new \Symfony\Component\VarDumper\Dumper\HtmlDumper();
            $dumper->dump($cloner->cloneVar($var));
        });
    }
}

// Validate database connection on startup (if enabled and not running CLI)
if (PHP_SAPI !== 'cli' && env('DB_STARTUP_VALIDATION', true) && !env('SKIP_DB_VALIDATION', false)) {
    \Glueful\Database\ConnectionValidator::validateOnStartup(
        throwOnFailure: env('DB_STARTUP_STRICT', false)
    );
}

// Return the container for use in index.php and other entry points
return $container;
