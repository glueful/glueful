<?php
// Composer autoloader
require __DIR__ . '/vendor/autoload.php';
// Load config
require_once __DIR__ . '/config/_config.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Error reporting based on environment
if ($_ENV['APP_ENV'] === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Set default timezone
date_default_timezone_set('UTC');

// Your application bootstrap code will go here
// For example, routing, database connection, etc.
