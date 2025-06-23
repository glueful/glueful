<?php

namespace Tests\Mocks;

/**
 * Mock Autoloader
 *
 * Provides methods to register and unregister mock classes.
 */
class MockAutoloader
{
    /** @var array Original class implementations */
    private static array $originals = [];

    /** @var bool Whether we're using autoloader approach */
    private static bool $initialized = false;

    /**
     * Register all mock classes
     */
    public static function register(): void
    {
        // Reset all mock data
        MockCacheStore::reset();
        MockAuditLogger::reset();

        // If runkit7 is available, use it for class replacement
        if (extension_loaded('runkit7')) {
            self::registerWithRunkit();
        } else {
            // Otherwise use autoloader approach
            self::registerWithAutoloader();
        }
    }

    /**
     * Register mocks using runkit7
     */
    private static function registerWithRunkit(): void
    {
        // Store original implementations if they exist
        if (class_exists('Glueful\Cache\CacheStore', true)) {
            self::$originals['Glueful\Cache\CacheStore'] = true;
        }
        if (class_exists('Glueful\Logging\AuditLogger', true)) {
            self::$originals['Glueful\Logging\AuditLogger'] = true;
        }
        if (class_exists('Glueful\Security\RateLimiterDistributor', true)) {
            self::$originals['Glueful\Security\RateLimiterDistributor'] = true;
        }
        if (class_exists('Glueful\Security\RateLimiter', true)) {
            self::$originals['Glueful\Security\RateLimiter'] = true;
        }
        if (class_exists('Glueful\Security\RateLimiterRule', true)) {
            self::$originals['Glueful\Security\RateLimiterRule'] = true;
        }
    }

    /**
     * Register mocks using autoloader approach
     */
    private static function registerWithAutoloader(): void
    {
        if (!self::$initialized) {
            // Register our autoloader before others
            spl_autoload_register([self::class, 'mockLoader'], true, true);
            self::$initialized = true;
        }
    }

    /**
     * Mock class autoloader
     */
    public static function mockLoader($className): bool
    {
        // Map of original class names to mock class names
        $classMap = [
            'Glueful\Cache\CacheStore' => MockCacheStore::class,
            'Glueful\Logging\AuditLogger' => MockAuditLogger::class,
            'Glueful\Security\RateLimiterDistributor' => MockRateLimiterDistributor::class,
            'Glueful\Security\RateLimiter' => MockRateLimiter::class,
            'Glueful\Security\RateLimiterRule' => MockRateLimiterRule::class,
            'Glueful\Security\AdaptiveRateLimiter' => MockAdaptiveRateLimiter::class,
        ];

        // If the requested class is in our map, load the mock class and create an alias
        if (isset($classMap[$className])) {
            $mockClass = $classMap[$className];

            // Make sure the mock class itself is loaded
            if (!class_exists($mockClass, true)) {
                return false;
            }

            // Create a dynamically generated proxy class if needed
            if (!class_exists($className, false)) {
                $namespaceParts = explode('\\', $className);
                $shortClassName = array_pop($namespaceParts);
                $namespace = implode('\\', $namespaceParts);

                // Dynamically define a class in the target namespace that extends our mock
                eval("
                namespace $namespace;
                class $shortClassName extends \\$mockClass {}
                ");

                return true;
            }
        }

        return false;
    }

    /**
     * Unregister all mock classes
     */
    public static function unregister(): void
    {
        // Reset all mock data
        MockCacheStore::reset();
        MockAuditLogger::reset();

        // Remove our autoloader if it was registered
        if (self::$initialized) {
            spl_autoload_unregister([self::class, 'mockLoader']);
            self::$initialized = false;
        }
    }
}
