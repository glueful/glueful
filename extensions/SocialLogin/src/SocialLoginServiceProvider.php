<?php

declare(strict_types=1);

namespace Glueful\Extensions\SocialLogin;

use Glueful\DI\ServiceProviders\BaseExtensionServiceProvider;
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
class SocialLoginServiceProvider extends BaseExtensionServiceProvider
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
     * @return void
     */
    protected function registerExtensionServices(): void
    {
        // Register Google Auth Provider
        $this->singleton(GoogleAuthProvider::class);

        // Register Facebook Auth Provider
        $this->singleton(FacebookAuthProvider::class);

        // Register GitHub Auth Provider
        $this->singleton(GithubAuthProvider::class);

        // Register Apple Auth Provider
        $this->singleton(AppleAuthProvider::class);

        // Register a factory for creating providers based on name
        $this->factory('SocialLogin.ProviderFactory', [self::class, 'createProviderFactory']);

        // Register social login configuration
        $this->factory('SocialLogin.Config', [self::class, 'createConfig']);
    }

    /**
     * Boot services after all providers have been registered
     *
     * @param \Glueful\DI\Container $container
     * @return void
     */
    public function boot(\Glueful\DI\Container $container): void
    {
        // Register providers with the authentication system
        $this->registerAuthProviders($container);
    }

    /**
     * Register social authentication providers with AuthBootstrap
     *
     * @param \Glueful\DI\Container $container
     * @return void
     */
    private function registerAuthProviders(\Glueful\DI\Container $container): void
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

    /**
     * Create provider factory
     *
     * @param \Glueful\DI\Container $container
     * @return callable
     */
    public static function createProviderFactory(\Glueful\DI\Container $container): callable
    {
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
    }

    /**
     * Create configuration array
     *
     * @return array
     */
    public static function createConfig(): array
    {
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
    }
}
