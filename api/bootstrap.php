<?php

// Load composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load .env file
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Glueful\ExceptionHandler::register();

// Initialize Cache Engine
Glueful\Helpers\Utils::initializeCacheEngine('glueful:');

// Initialize API Engine
Glueful\APIEngine::initialize();
