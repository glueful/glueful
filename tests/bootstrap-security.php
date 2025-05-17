<?php

require_once __DIR__ . '/Mocks/config_helper.php';
require_once __DIR__ . '/Mocks/AuditEvent.php';
require_once __DIR__ . '/Mocks/MockCacheEngine.php';
require_once __DIR__ . '/Mocks/MockAuditLogger.php';
require_once __DIR__ . '/Mocks/MockRateLimiterDistributor.php';
require_once __DIR__ . '/Mocks/MockRateLimiter.php';
require_once __DIR__ . '/Mocks/MockRateLimiterRule.php';
require_once __DIR__ . '/Mocks/MockAutoloader.php';

// Register the autoloader
spl_autoload_register(function ($class) {
    // Check if we need to manually load the AdaptiveRateLimiter
    if ($class === 'Glueful\Security\AdaptiveRateLimiter') {
        require_once __DIR__ . '/../api/Security/AdaptiveRateLimiter.php';
        return true;
    }

    return false;
});
