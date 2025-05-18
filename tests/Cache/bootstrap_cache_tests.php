<?php

/**
 * Cache Tests Bootstrap
 *
 * This file sets up the testing environment for cache tests, loading
 * all required mocks and dependencies.
 */

// Load our Redis mock if Redis extension is not available
if (!class_exists('Redis', false)) {
    require_once __DIR__ . '/../Mocks/Redis.php';
}

// Load the test node classes
require_once __DIR__ . '/Nodes/MemoryNode.php';
require_once __DIR__ . '/Nodes/MockTestCacheNode.php';
require_once __DIR__ . '/Helpers/MockCacheNodePatcher.php';

// Register the autoloader for cache-related classes
spl_autoload_register(function ($class) {
    // Handle CacheNode mocking
    if ($class === 'Glueful\\Cache\\Nodes\\CacheNode') {
        class_alias('Glueful\\Tests\\Cache\\Nodes\\MockTestCacheNode', $class);
        return true;
    }

    return false;
}, true, true);

// Initialize the mock patcher
\Glueful\Tests\Cache\Helpers\CacheNodePatcher::registerMemoryNode();
