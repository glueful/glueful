<?php

declare(strict_types=1);

namespace Glueful\Configuration\Schema;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;

/**
 * Database Configuration Schema
 *
 * Defines validation rules and structure for database configuration including
 * connection settings, pooling options, and driver-specific validation.
 *
 * @package Glueful\Configuration\Schema
 */
class DatabaseConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('database');
        $rootNode = $treeBuilder->getRootNode();
        // @phpstan-ignore-next-line TreeBuilder root nodes are ArrayNodeDefinitions in practice
        $rootNode
            ->children()
                ->arrayNode('connections')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('driver')
                                ->values(['mysql', 'postgresql', 'sqlite'])
                                ->defaultValue('mysql')
                                ->info('Database driver to use')
                            ->end()
                            ->scalarNode('host')
                                ->defaultValue('localhost')
                                ->info('Database host')
                            ->end()
                            ->integerNode('port')
                                ->min(1)
                                ->max(65535)
                                ->defaultValue(3306)
                                ->info('Database port')
                            ->end()
                            ->scalarNode('database')
                                ->cannotBeEmpty()
                                ->info('Database name')
                            ->end()
                            ->scalarNode('username')
                                ->cannotBeEmpty()
                                ->info('Database username')
                            ->end()
                            ->scalarNode('password')
                                ->defaultValue('')
                                ->info('Database password')
                            ->end()
                            ->scalarNode('charset')
                                ->defaultValue('utf8mb4')
                                ->info('Database charset')
                            ->end()
                        ->end()
                        ->validate()
                            ->ifTrue(function ($v) {
                                return $v['driver'] === 'mysql' && $v['port'] !== 3306 && !isset($v['port']);
                            })
                            ->thenInvalid('MySQL driver requires port to be specified or default 3306')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('pool')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Enable connection pooling')
                        ->end()
                        ->integerNode('min_connections')
                            ->min(1)
                            ->defaultValue(5)
                            ->info('Minimum connections in pool')
                        ->end()
                        ->integerNode('max_connections')
                            ->min(1)
                            ->defaultValue(20)
                            ->info('Maximum connections in pool')
                        ->end()
                        ->integerNode('idle_timeout')
                            ->min(1)
                            ->defaultValue(300)
                            ->info('Connection idle timeout in seconds')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    public function getConfigurationName(): string
    {
        return 'database';
    }

    public function getDescription(): string
    {
        return 'Database connection and pooling configuration';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }
}
