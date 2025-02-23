<?php

// Load composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Load .env file
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

// Load helpers (must be loaded before any config files)
require_once __DIR__ . '/helpers.php';
// require_once __DIR__ . '/api-library/ExceptionHandler.php';
// Glueful\Api\Library\ExceptionHandler::register();