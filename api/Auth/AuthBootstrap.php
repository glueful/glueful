<?php

declare(strict_types=1);

namespace Glueful\Auth;

/**
 * Authentication Bootstrapper
 *
 * Configures and initializes the authentication system.
 * Creates a singleton AuthenticationManager instance with registered providers.
 */
class AuthBootstrap
{
    /** @var AuthenticationManager|null The singleton manager instance */
    private static ?AuthenticationManager $manager = null;

    /**
     * Initialize the authentication system
     *
     * Creates and configures the AuthenticationManager with available providers.
     *
     * @return AuthenticationManager The configured authentication manager
     */
    public static function initialize(): AuthenticationManager
    {
        if (self::$manager !== null) {
            return self::$manager;
        }

        // Create default authentication providers
        $jwtProvider = new JwtAuthenticationProvider();
        $apiKeyProvider = new ApiKeyAuthenticationProvider();
        $adminProvider = new AdminAuthenticationProvider();

        // Create manager with JWT provider as default
        $manager = new AuthenticationManager($jwtProvider);

        // Register additional providers
        $manager->registerProvider('jwt', $jwtProvider);
        $manager->registerProvider('api_key', $apiKeyProvider);
        $manager->registerProvider('admin', $adminProvider);

        // Register additional custom providers
        self::registerCustomProviders($manager);

        // Store the singleton instance
        self::$manager = $manager;

        return $manager;
    }

    /**
     * Register custom authentication providers from configuration
     *
     * @param AuthenticationManager $manager The manager to configure
     */
    private static function registerCustomProviders(AuthenticationManager $manager): void
    {
        // Get configured providers from configuration
        $configuredProviders = config('session.providers', []);

        foreach ($configuredProviders as $name => $providerClass) {
            // Skip if already registered
            if ($manager->getProvider($name)) {
                continue;
            }

            try {
                // Skip if the class doesn't exist
                if (!class_exists($providerClass)) {
                    continue;
                }

                // Create and register the provider if it implements the interface
                $provider = new $providerClass();
                if ($provider instanceof AuthenticationProviderInterface) {
                    $manager->registerProvider($name, $provider);
                }
            } catch (\Throwable $e) {
                // Log error and continue with other providers
                error_log("Failed to register authentication provider '{$name}': " . $e->getMessage());
            }
        }
    }

    /**
     * Get the authentication manager instance
     *
     * @return AuthenticationManager The manager instance
     */
    public static function getManager(): AuthenticationManager
    {
        return self::$manager ?? self::initialize();
    }
}
