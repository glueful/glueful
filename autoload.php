<?php

// Load Composer's autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// // Load helper functions
// require_once __DIR__ . '/api/config/helpers.php';

// Load config
require_once __DIR__ . '/config/_config.php';