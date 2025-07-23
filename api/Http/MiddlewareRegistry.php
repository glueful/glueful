<?php

declare(strict_types=1);

namespace Glueful\Http;

use Glueful\Http\Router;
use Glueful\Http\Middleware\MiddlewareInterface;
use Glueful\Http\Middleware\CSRFMiddleware;
use Glueful\Http\Middleware\RateLimiterMiddleware;
use Glueful\Http\Middleware\SecurityHeadersMiddleware;
use Glueful\Http\Middleware\MemoryTrackingMiddleware;
use Glueful\Http\Middleware\EdgeCacheMiddleware;
use Glueful\DI\Container;

/**
 * Improved Middleware Registry
 *
 * Handles middleware registration without configuration duplication.
 * References settings from dedicated configuration files and supports
 * both simple class names and complex configuration objects.
 */
class MiddlewareRegistry
{
    private static bool $registered = false;
    private static ?Container $container = null;
    private static array $registeredClasses = [];

    /**
     * Register middleware from configuration with duplication prevention
     *
     * @param Container|null $container DI container
     * @param bool $replaceManualRegistration Whether to replace manual registrations
     * @return void
     */
    public static function registerFromConfig(
        ?Container $container = null,
        bool $replaceManualRegistration = true
    ): void {
        if (self::$registered) {
            return; // Prevent double registration
        }

        self::$container = $container ?? self::getDefaultContainer();

        // Load middleware configuration
        $middlewareConfig = config('middleware', []);

        // Check if we should replace manual registration
        $shouldReplace = $replaceManualRegistration &&
                        ($middlewareConfig['settings']['replace_manual_registration'] ?? true);

        if ($shouldReplace) {
            // Clear any manually registered middleware to prevent duplicates
            self::clearManualMiddleware();
        }

        // Register global middleware
        if (isset($middlewareConfig['global']) && is_array($middlewareConfig['global'])) {
            self::registerGlobalMiddleware($middlewareConfig['global']);
        }

        self::$registered = true;

        // Log registration if enabled
        if ($middlewareConfig['settings']['log_registration'] ?? false) {
            error_log("MiddlewareRegistry: Registered " . count(self::$registeredClasses) . " middleware classes");
        }
    }

    /**
     * Clear manually registered middleware (if possible)
     * Note: This is a conceptual method - actual implementation depends on Router internals
     *
     * @return void
     */
    private static function clearManualMiddleware(): void
    {
        // In a real implementation, you might need Router::clearMiddleware() method
        // For now, we'll track what we register to avoid duplicates
        self::$registeredClasses = [];
    }

    /**
     * Register global middleware stack
     *
     * @param array $middlewareList List of middleware configurations
     * @return void
     */
    private static function registerGlobalMiddleware(array $middlewareList): void
    {
        foreach ($middlewareList as $middlewareSpec) {
            $middleware = self::resolveMiddleware($middlewareSpec);
            if ($middleware) {
                $className = get_class($middleware);

                // Prevent duplicate registration
                if (!in_array($className, self::$registeredClasses)) {
                    Router::addMiddleware($middleware);
                    self::$registeredClasses[] = $className;
                }
            }
        }
    }

    /**
     * Resolve middleware from specification with config reference support
     *
     * @param mixed $middlewareSpec Middleware specification
     * @return MiddlewareInterface|null Resolved middleware instance
     */
    private static function resolveMiddleware($middlewareSpec): ?MiddlewareInterface
    {
        // Simple class name
        if (is_string($middlewareSpec)) {
            return self::createMiddlewareInstance($middlewareSpec);
        }

        // Configuration array
        if (is_array($middlewareSpec) && isset($middlewareSpec['class'])) {
            $className = $middlewareSpec['class'];

            // Handle config references
            if (isset($middlewareSpec['config_ref'])) {
                $config = config($middlewareSpec['config_ref'], []);
                $params = $middlewareSpec['params'] ?? [];
                return self::createMiddlewareWithConfigRef($className, $config, $params);
            }

            // Direct config
            $config = $middlewareSpec['config'] ?? [];
            return self::createMiddlewareInstance($className, $config);
        }

        return null;
    }

    /**
     * Create middleware instance with configuration reference
     *
     * @param string $className Middleware class name
     * @param array $config Configuration from referenced file
     * @param array $params Parameter mapping
     * @return MiddlewareInterface|null Created middleware instance
     */
    private static function createMiddlewareWithConfigRef(
        string $className,
        array $config,
        array $params = []
    ): ?MiddlewareInterface {
        try {
            switch ($className) {
                case RateLimiterMiddleware::class:
                    return new RateLimiterMiddleware(
                        $config[$params['max_attempts']] ?? 60,
                        $config[$params['window_seconds']] ?? 60,
                        $params['key_type'] ?? 'ip',
                        $config[$params['enable_adaptive']] ?? true,
                        $config[$params['enable_distributed']] ?? false
                    );

                case SecurityHeadersMiddleware::class:
                    return new SecurityHeadersMiddleware($config);

                case MemoryTrackingMiddleware::class:
                    // MemoryTrackingMiddleware requires MemoryManager, LoggerInterface is optional
                    if (self::$container) {
                        try {
                            $memoryManager = self::$container->get('Glueful\\Performance\\MemoryManager');

                            // Try to get logger, but don't fail if it's not available
                            $logger = null;
                            try {
                                $logger = self::$container->get('Psr\\Log\\LoggerInterface');
                            } catch (\Exception) {
                                // Logger not available, will use NullLogger fallback
                            }

                            return new MemoryTrackingMiddleware($memoryManager, $logger);
                        } catch (\Exception $e) {
                            error_log("MemoryTrackingMiddleware: Failed to resolve dependencies - " . $e->getMessage());
                            return null;
                        }
                    }
                    return null;

                case EdgeCacheMiddleware::class:
                    // EdgeCacheMiddleware may not implement MiddlewareInterface
                    // and requires EdgeCacheService, not array config
                    if (self::$container && self::$container->has('Glueful\Cache\EdgeCacheService')) {
                        $cacheService = self::$container->get('Glueful\Cache\EdgeCacheService');
                        return new EdgeCacheMiddleware($cacheService);
                    }
                    return null;

                default:
                    // Try generic instantiation
                    return self::createMiddlewareInstance($className, $config);
            }
        } catch (\Exception $e) {
            error_log("MiddlewareRegistry: Error creating {$className} with config ref: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create middleware instance with optional configuration
     *
     * @param string $className Middleware class name
     * @param array $config Configuration for the middleware
     * @return MiddlewareInterface|null Created middleware instance
     */
    private static function createMiddlewareInstance(string $className, array $config = []): ?MiddlewareInterface
    {
        try {
            // Special handling for specific middleware types
            switch ($className) {
                case CSRFMiddleware::class:
                    return new CSRFMiddleware(
                        exemptRoutes: $config['exemptRoutes'] ?? [],
                        tokenLifetime: (int)($config['tokenLifetime'] ?? 3600),
                        useDoubleSubmit: (bool)($config['useDoubleSubmit'] ?? false),
                        enabled: (bool)($config['enabled'] ?? true),
                        container: self::$container
                    );

                default:
                    // Try DI container first
                    if (self::$container && self::$container->has($className)) {
                        $instance = self::$container->get($className);
                        if ($instance instanceof MiddlewareInterface) {
                            return $instance;
                        }
                    }

                    // Fallback to direct instantiation
                    if (class_exists($className)) {
                        $instance = new $className();
                        if ($instance instanceof MiddlewareInterface) {
                            return $instance;
                        }
                    }
            }

            error_log("MiddlewareRegistry: Failed to create middleware instance for {$className}");
            return null;
        } catch (\Exception $e) {
            error_log("MiddlewareRegistry: Error creating middleware {$className}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if a middleware class has been registered
     *
     * @param string $className Middleware class name
     * @return bool Whether the middleware is registered
     */
    public static function isMiddlewareRegistered(string $className): bool
    {
        return in_array($className, self::$registeredClasses);
    }

    /**
     * Get list of registered middleware classes
     *
     * @return array List of registered middleware class names
     */
    public static function getRegisteredMiddleware(): array
    {
        return self::$registeredClasses;
    }

    /**
     * Get default DI container
     *
     * @return Container|null
     */
    private static function getDefaultContainer(): ?Container
    {
        if (function_exists('container')) {
            try {
                return container();
            } catch (\Exception) {
                return null;
            }
        }
        return null;
    }

    /**
     * Reset registration state
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$registered = false;
        self::$container = null;
        self::$registeredClasses = [];
    }

    /**
     * Check if middleware has been registered
     *
     * @return bool Whether middleware has been registered
     */
    public static function isRegistered(): bool
    {
        return self::$registered;
    }
}
