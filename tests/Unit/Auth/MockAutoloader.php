<?php
namespace Tests\Unit\Auth;

use Tests\Unit\Mocks\MockCacheEngine;

/**
 * Mock autoloader for testing
 *
 * This file provides mock implementations of some Glueful classes for testing.
 * It intercepts class loading requests and returns mock classes when appropriate.
 */

// We need to autoload our mock classes when the real ones are requested in tests
spl_autoload_register(function ($class) {
    // Map of classes to replace with mocks during tests
    $classMaps = [
        'Glueful\\Cache\\CacheEngine' => MockCacheEngine::class
    ];

    // If the requested class is in our map, include our mock version
    if (isset($classMaps[$class])) {
        // The mock class should already be autoloaded by PHPUnit
        class_alias($classMaps[$class], $class);
        return true;
    }

    return false;
}, true, true); // prepend this autoloader
