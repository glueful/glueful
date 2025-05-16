<?php // phpcs:ignore PSR1.Files.SideEffects.FoundWithSymbols

/**
 * PHPUnit Bootstrap file
 * Sets up the test environment for Glueful tests
 */

// Include Composer autoload to load all dependencies
require_once __DIR__ . '/../vendor/autoload.php';

// Set up environment for testing
define('TESTING_ENV', true);
define('SKIP_DB_INIT', true); // Prevents automatic database initialization

// Load testing environment variables if available
if (file_exists(__DIR__ . '/../.env.testing')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..', '.env.testing');
    $dotenv->load();
} else {
    // If no testing environment file, use the standard one but set APP_ENV to testing
    if (file_exists(__DIR__ . '/../.env')) {
        $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
        // Override the environment to testing
        $_ENV['APP_ENV'] = 'testing';
        $_SERVER['APP_ENV'] = 'testing';
    }
}

// Additional bootstrap code for tests
// Some constants that might be needed by the tests
define('TEST_ROOT', __DIR__);
define('APP_ROOT', __DIR__ . '/..');

// Load extensions bootstrap to set up namespaces for extensions
require_once __DIR__ . '/bootstrap-extensions.php';

// Optionally set up a test database
// This would typically be done in specific integration tests
