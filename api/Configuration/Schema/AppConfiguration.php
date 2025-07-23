<?php

declare(strict_types=1);

namespace Glueful\Configuration\Schema;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;

/**
 * Application Configuration Schema
 *
 * Defines validation rules and structure for core application settings including
 * environment configuration, feature flags, security settings, and business rules.
 *
 * @package Glueful\Configuration\Schema
 */
class AppConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('app');
        $rootNode = $treeBuilder->getRootNode();

        // @phpstan-ignore-next-line TreeBuilder root nodes are ArrayNodeDefinitions in practice
        $rootNode
            ->children()
                ->scalarNode('name')
                    ->defaultValue('Glueful API')
                    ->info('Application name')
                ->end()
                ->scalarNode('version')
                    ->cannotBeEmpty()
                    ->info('Application version')
                ->end()
                ->enumNode('env')
                    ->values(['development', 'testing', 'staging', 'production'])
                    ->defaultValue('production')
                    ->info('Application environment')
                ->end()
                ->booleanNode('debug')
                    ->defaultValue(false)
                    ->info('Enable debug mode')
                ->end()
                ->scalarNode('timezone')
                    ->defaultValue('UTC')
                    ->validate()
                        ->ifTrue(function ($v) {
                            return !in_array($v, timezone_identifiers_list());
                        })
                        ->thenInvalid('Invalid timezone: %s')
                    ->end()
                    ->info('Application timezone')
                ->end()
                ->arrayNode('features')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('api_docs')
                            ->defaultValue(false)
                            ->info('Enable API documentation')
                        ->end()
                        ->booleanNode('debug_mode')
                            ->defaultValue(false)
                            ->info('Enable debug features')
                        ->end()
                        ->booleanNode('extensions')
                            ->defaultTrue()
                            ->info('Enable extension system')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('key')
                            ->cannotBeEmpty()
                            ->info('Application security key')
                        ->end()
                        ->arrayNode('allowed_hosts')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Allowed hosts for requests')
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(function ($v) {
                    return $v['env'] === 'production' && $v['debug'] === true;
                })
                ->thenInvalid('Debug mode cannot be enabled in production environment')
            ->end()
            ->validate()
                ->ifTrue(function ($v) {
                    return $v['env'] === 'production' && $v['features']['api_docs'] === true;
                })
                ->thenInvalid('API documentation should not be enabled in production')
            ->end();

        return $treeBuilder;
    }

    public function getConfigurationName(): string
    {
        return 'app';
    }

    public function getDescription(): string
    {
        return 'Core application configuration settings';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }
}
