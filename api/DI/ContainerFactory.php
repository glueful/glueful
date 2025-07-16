<?php

declare(strict_types=1);

namespace Glueful\DI;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;
use Glueful\DI\Passes\TaggedServicePass;
use Glueful\DI\Passes\ExtensionServicePass;

/**
 * Pure Symfony Container Factory - No legacy code
 */
class ContainerFactory
{
    public static function create(bool $isProduction = false): Container
    {
        if ($isProduction && self::hasCompiledContainer()) {
            return self::loadCompiledContainer();
        }

        return self::buildDevelopmentContainer();
    }

    private static function buildDevelopmentContainer(): Container
    {
        $builder = new ContainerBuilder();
        self::configureContainer($builder);
        $builder->compile();

        return new Container($builder);
    }

    public static function configureContainer(ContainerBuilder $builder): void
    {
        self::setupParameters($builder);
        self::addCompilerPasses($builder);
        self::loadServiceProviders($builder);
        self::loadConfigurationFiles($builder);
        self::loadExtensionServices($builder);
    }

    private static function setupParameters(ContainerBuilder $builder): void
    {
        // Add framework parameters
        $builder->setParameter('glueful.env', $_ENV['APP_ENV'] ?? 'production');
        $builder->setParameter('glueful.debug', $_ENV['APP_DEBUG'] ?? false);
        $builder->setParameter('glueful.version', '1.0.0');
        $builder->setParameter('glueful.root_dir', dirname(__DIR__, 3));
        $builder->setParameter('glueful.config_dir', 'config');
        $builder->setParameter('glueful.storage_dir', 'storage');
        $builder->setParameter('glueful.cache_dir', 'storage/cache');
        $builder->setParameter('glueful.log_dir', 'storage/logs');

        // Add validation configuration parameter
        $builder->setParameter('validation.config', [
            'cache_enabled' => true,
            'cache_ttl' => 3600,
            'lazy_loading' => true,
            'preload_common' => true,
            'debug' => $_ENV['APP_DEBUG'] ?? false,
        ]);

        // Add filesystem configuration parameters
        $builder->setParameter('filesystem.file_manager', [
            'root_path' => dirname(__DIR__, 3),
            'temp_path' => 'storage/tmp',
            'max_file_size' => 10 * 1024 * 1024, // 10MB
            'allowed_extensions' => ['php', 'json', 'txt', 'md', 'yml', 'yaml'],
            'forbidden_paths' => ['.git', 'vendor', 'node_modules'],
        ]);

        $builder->setParameter('filesystem.file_finder', [
            'search_paths' => ['api', 'config', 'extensions'],
            'excluded_dirs' => ['vendor', 'node_modules', '.git', 'storage/cache'],
            'max_depth' => 10,
            'case_sensitive' => true,
        ]);

        // Add archive configuration parameter
        $builder->setParameter('archive.config', [
            'enabled' => true,
            'retention_days' => 365,
            'compression' => 'gzip',
            'archive_table' => 'archived_data',
            'batch_size' => 1000,
            'max_archive_size' => 100 * 1024 * 1024, // 100MB
            'storage_path' => 'storage/archives',
            'auto_cleanup' => true,
        ]);
    }

    private static function addCompilerPasses(ContainerBuilder $builder): void
    {
        $builder->addCompilerPass(new TaggedServicePass());
        $builder->addCompilerPass(new ExtensionServicePass());
    }

    private static function loadServiceProviders(ContainerBuilder $builder): void
    {
        $providers = self::getServiceProviders();

        foreach ($providers as $provider) {
            $provider->register($builder);

            // Add compiler passes from provider
            foreach ($provider->getCompilerPasses() as $pass) {
                $builder->addCompilerPass($pass);
            }
        }
    }

    private static function getServiceProviders(): array
    {
        $providers = [];

        // Get service provider instances from their registration files
        $providerClasses = [
            \Glueful\DI\ServiceProviders\CoreServiceProvider::class,
            \Glueful\DI\ServiceProviders\ConfigServiceProvider::class,
            \Glueful\DI\ServiceProviders\VarDumperServiceProvider::class,
            \Glueful\DI\ServiceProviders\ConsoleServiceProvider::class,
            \Glueful\DI\ServiceProviders\ValidatorServiceProvider::class,
            \Glueful\DI\ServiceProviders\HttpClientServiceProvider::class,
            \Glueful\DI\ServiceProviders\SerializerServiceProvider::class,
            \Glueful\DI\ServiceProviders\FileServiceProvider::class,
            \Glueful\DI\ServiceProviders\RequestServiceProvider::class,
            \Glueful\DI\ServiceProviders\SecurityServiceProvider::class,
            \Glueful\DI\ServiceProviders\ControllerServiceProvider::class,
            \Glueful\DI\ServiceProviders\RepositoryServiceProvider::class,
            \Glueful\DI\ServiceProviders\ExtensionServiceProvider::class,
            \Glueful\DI\ServiceProviders\EventServiceProvider::class,
            \Glueful\DI\ServiceProviders\QueueServiceProvider::class,
            \Glueful\DI\ServiceProviders\LockServiceProvider::class,
            \Glueful\DI\ServiceProviders\ArchiveServiceProvider::class,
        ];

        foreach ($providerClasses as $providerClass) {
            if (class_exists($providerClass)) {
                $providers[] = new $providerClass();
            }
        }

        return $providers;
    }

    private static function loadConfigurationFiles(ContainerBuilder $builder): void
    {
        // Service definitions will be handled by service providers in Phase 4
        // This method is reserved for optional YAML service configurations

        // Load YAML services if they exist (optional supplementary configuration)
        if (file_exists('config/services.yaml')) {
            $yamlLoader = new YamlFileLoader($builder, new FileLocator('config/'));
            $yamlLoader->load('services.yaml');
        }
    }

    private static function loadExtensionServices(ContainerBuilder $builder): void
    {
        // Load extension services from extensions.json
        $extensionsConfig = 'extensions/extensions.json';
        if (file_exists($extensionsConfig)) {
            $extensions = json_decode(file_get_contents($extensionsConfig), true);

            foreach ($extensions as $extension => $config) {
                if ($config['enabled'] ?? false) {
                    $servicesFile = "extensions/{$extension}/config/services.php";
                    if (file_exists($servicesFile)) {
                        $loader = new PhpFileLoader($builder, new FileLocator("extensions/{$extension}/config/"));
                        $loader->load('services.php');
                    }
                }
            }
        }
    }

    private static function hasCompiledContainer(): bool
    {
        $storageDir = config('app.paths.storage');
        $containerFile = "{$storageDir}/container/CompiledContainer.php";

        if (!file_exists($containerFile)) {
            return false;
        }

        // Also check if the class would be loadable
        require_once $containerFile;
        return class_exists('Glueful\\DI\\Compiled\\CompiledContainer');
    }

    private static function loadCompiledContainer(): Container
    {
        $storageDir = config('app.paths.storage');
        $containerFile = "{$storageDir}/container/CompiledContainer.php";

        if (!file_exists($containerFile)) {
            // Fallback to development container if compiled doesn't exist
            return self::buildDevelopmentContainer();
        }

        require_once $containerFile;

        // Compiled container class is generated at runtime by Symfony PhpDumper
        // Use dynamic class instantiation to avoid static analysis errors
        $containerClass = 'Glueful\\DI\\Compiled\\CompiledContainer';

        if (!class_exists($containerClass)) {
            // Fallback to development container if class doesn't exist
            return self::buildDevelopmentContainer();
        }

        /** @var \Symfony\Component\DependencyInjection\ContainerInterface $compiledContainer */
        $compiledContainer = new $containerClass();

        return new Container($compiledContainer);
    }
}
