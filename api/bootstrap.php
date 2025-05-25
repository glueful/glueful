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

// Initialize API Engine
Glueful\APIEngine::initialize();
