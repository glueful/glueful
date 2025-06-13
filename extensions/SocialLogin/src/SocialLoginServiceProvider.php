<?php

declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin;

use Glueful\DI\Providers\ExtensionServiceProvider;
use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\Extensions\SocialLogin\Providers\GoogleAuthProvider;
use Glueful\Extensions\SocialLogin\Providers\FacebookAuthProvider;
use Glueful\Extensions\SocialLogin\Providers\GithubAuthProvider;
use Glueful\Extensions\SocialLogin\Providers\AppleAuthProvider;
use Glueful\Auth\AuthBootstrap;

/**
 * Service Provider for SocialLogin Extension
 *
 * Registers all social authentication providers and related services
 * with the dependency injection container.
 */
class SocialLoginServiceProvider extends ExtensionServiceProvider
{
    /**
     * Get the extension name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'SocialLogin';
    }

    /**
     * Get the extension version
     *
     * @return string
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Get the extension description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Social authentication provider supporting Google, Facebook, GitHub, and Apple login';
    }

    /**
     * Register services in the container
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function register(ContainerInterface $container): void
    {
        // Register Google Auth Provider
        $container->singleton(GoogleAuthProvider::class, function ($container) {
            return new GoogleAuthProvider();
        });

        // Register Facebook Auth Provider
        $container->singleton(FacebookAuthProvider::class, function ($container) {
            return new FacebookAuthProvider();
        });

        // Register GitHub Auth Provider
        $container->singleton(GithubAuthProvider::class, function ($container) {
            return new GithubAuthProvider();
        });

        // Register Apple Auth Provider
        $container->singleton(AppleAuthProvider::class, function ($container) {
            return new AppleAuthProvider();
        });

        // Register a factory for creating providers based on name
        $container->singleton('SocialLogin.ProviderFactory', function ($container) {
            return function (string $providerName) use ($container) {
                switch ($providerName) {
                    case 'google':
                        return $container->get(GoogleAuthProvider::class);
                    case 'facebook':
                        return $container->get(FacebookAuthProvider::class);
                    case 'github':
                        return $container->get(GithubAuthProvider::class);
                    case 'apple':
                        return $container->get(AppleAuthProvider::class);
                    default:
                        throw new \InvalidArgumentException("Unsupported social provider: {$providerName}");
                }
            };
        });

        // Register social login configuration
        $container->singleton('SocialLogin.Config', function ($container) {
            $configPath = __DIR__ . '/config.php';
            if (file_exists($configPath)) {
                return require $configPath;
            }

            // Default configuration
            return [
                'enabled_providers' => ['google', 'facebook', 'github', 'apple'],
                'auto_register' => true,
                'link_accounts' => true,
                'sync_profile' => true,
            ];
        });
    }

    /**
     * Boot services after all providers have been registered
     *
     * @param ContainerInterface $container
     * @return void
     */
    public function boot(ContainerInterface $container): void
    {
        // Register providers with the authentication system
        $this->registerAuthProviders($container);
    }

    /**
     * Register social authentication providers with AuthBootstrap
     *
     * @param ContainerInterface $container
     * @return void
     */
    private function registerAuthProviders(ContainerInterface $container): void
    {
        try {
            // Get the authentication manager
            $authManager = AuthBootstrap::getManager();

            // Get configuration
            $config = $container->get('SocialLogin.Config');
            $enabledProviders = $config['enabled_providers'] ?? [];

            // Get provider factory
            $providerFactory = $container->get('SocialLogin.ProviderFactory');

            // Register each enabled provider
            foreach ($enabledProviders as $providerName) {
                try {
                    $provider = $providerFactory($providerName);
                    $authManager->registerProvider($providerName, $provider);
                } catch (\Exception $e) {
                    error_log("Failed to register {$providerName} auth provider: " . $e->getMessage());
                }
            }
        } catch (\Exception $e) {
            error_log("Failed to register social auth providers: " . $e->getMessage());
        }
    }
}
