<?php

declare(strict_types=1);

namespace Glueful\Configuration\Extension;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;

/**
 * Extension Manifest Schema
 *
 * Defines validation rules and structure for extension manifest.json files
 * including metadata, dependencies, capabilities, and compatibility settings.
 *
 * @package Glueful\Configuration\Extension
 */
class ExtensionManifestSchema implements ExtensionSchemaInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('extension_manifest');
        $rootNode = $treeBuilder->getRootNode();

        // @phpstan-ignore-next-line TreeBuilder root nodes are ArrayNodeDefinitions in practice
        $rootNode
            ->children()
                ->enumNode('manifestVersion')
                    ->values(['1.0', '2.0'])
                    ->isRequired()
                    ->info('Manifest version specification')
                ->end()
                ->scalarNode('id')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(function ($v) {
                            return !preg_match('/^[a-z][a-z0-9_-]*[a-z0-9]$/', $v);
                        })
                        ->thenInvalid('Extension ID must be lowercase alphanumeric with dashes/underscores')
                    ->end()
                    ->info('Unique extension identifier')
                ->end()
                ->scalarNode('name')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(function ($v) {
                            return !preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $v);
                        })
                        ->thenInvalid('Extension name must be alphanumeric and start with a letter')
                    ->end()
                    ->info('Extension class name')
                ->end()
                ->scalarNode('displayName')
                    ->cannotBeEmpty()
                    ->info('Human-readable extension name')
                ->end()
                ->scalarNode('version')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(function ($v) {
                            return !preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9]+)?$/', $v);
                        })
                        ->thenInvalid('Version must follow semantic versioning (x.y.z)')
                    ->end()
                    ->info('Extension version')
                ->end()
                ->scalarNode('description')
                    ->defaultValue('')
                    ->info('Extension description')
                ->end()
                ->arrayNode('author')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('name')
                            ->cannotBeEmpty()
                            ->info('Author name')
                        ->end()
                        ->scalarNode('email')
                            ->validate()
                                ->ifTrue(function ($v) {
                                    return !empty($v) && !filter_var($v, FILTER_VALIDATE_EMAIL);
                                })
                                ->thenInvalid('Author email must be a valid email address')
                            ->end()
                            ->info('Author email')
                        ->end()
                        ->scalarNode('url')
                            ->validate()
                                ->ifTrue(function ($v) {
                                    return !empty($v) && !filter_var($v, FILTER_VALIDATE_URL);
                                })
                                ->thenInvalid('Author URL must be a valid URL')
                            ->end()
                            ->info('Author website URL')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('publisher')
                    ->cannotBeEmpty()
                    ->info('Extension publisher')
                ->end()
                ->enumNode('license')
                    ->values(['MIT', 'GPL-2.0', 'GPL-3.0', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC'])
                    ->defaultValue('MIT')
                    ->info('Extension license')
                ->end()
                ->scalarNode('main')
                    ->cannotBeEmpty()
                    ->info('Main extension file')
                ->end()
                ->scalarNode('main_class')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->validate()
                        ->ifTrue(function ($v) {
                            return !preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $v);
                        })
                        ->thenInvalid('Main class must be a valid PHP class name')
                    ->end()
                    ->info('Main extension class')
                ->end()
                ->arrayNode('autoload')
                    ->normalizeKeys(false)
                    ->children()
                        ->arrayNode('psr-4')
                            ->useAttributeAsKey('namespace')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(function ($v) {
                                        return !preg_match('/^[a-zA-Z0-9_\.\/]+\/?$/', $v);
                                    })
                                    ->thenInvalid('PSR-4 path must be a valid directory path')
                                ->end()
                            ->end()
                            ->info('PSR-4 autoload mappings')
                        ->end()
                        ->arrayNode('files')
                            ->scalarPrototype()->end()
                            ->info('Files to include')
                        ->end()
                    ->end()
                    ->info('Autoload configuration')
                ->end()
                ->arrayNode('categories')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(function ($v) {
                                return !preg_match('/^[a-z][a-z0-9-]*$/', $v);
                            })
                            ->thenInvalid('Category must be lowercase with hyphens only')
                        ->end()
                    ->end()
                    ->info('Extension categories')
                ->end()
                ->arrayNode('keywords')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(function ($v) {
                                return strlen($v) > 50;
                            })
                            ->thenInvalid('Keywords must be 50 characters or less')
                        ->end()
                    ->end()
                    ->info('Extension keywords for search')
                ->end()
                ->arrayNode('engines')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('glueful')
                            ->defaultValue('>=0.27.0')
                            ->validate()
                                ->ifTrue(function ($v) {
                                    return !preg_match('/^[><=~^]*\d+\.\d+(\.\d+)?/', $v);
                                })
                                ->thenInvalid('Invalid version constraint for Glueful')
                            ->end()
                            ->info('Required Glueful version')
                        ->end()
                        ->scalarNode('php')
                            ->defaultValue('>=8.2.0')
                            ->validate()
                                ->ifTrue(function ($v) {
                                    return !preg_match('/^[><=~^]*\d+\.\d+(\.\d+)?/', $v);
                                })
                                ->thenInvalid('Invalid version constraint for PHP')
                            ->end()
                            ->info('Required PHP version')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('dependencies')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('composer')
                            ->useAttributeAsKey('package')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(function ($v) {
                                        return !preg_match('/^[><=~^]*\d+\.\d+(\.\d+)?/', $v);
                                    })
                                    ->thenInvalid('Invalid version constraint')
                                ->end()
                            ->end()
                            ->defaultValue([])
                            ->info('Composer package dependencies')
                        ->end()
                        ->arrayNode('extensions')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(function ($v) {
                                        return !preg_match('/^[a-z][a-z0-9_-]*[a-z0-9]$/', $v);
                                    })
                                    ->thenInvalid('Extension dependency must be valid extension ID')
                                ->end()
                            ->end()
                            ->defaultValue([])
                            ->info('Required extensions')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('provides')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('services')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Provided services')
                        ->end()
                        ->arrayNode('commands')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Provided CLI commands')
                        ->end()
                        ->arrayNode('middleware')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Provided middleware')
                        ->end()
                        ->arrayNode('routes')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Provided route groups')
                        ->end()
                        ->arrayNode('channels')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                            ->info('Provided notification channels')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('capabilities')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(function ($v) {
                                return !preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)*$/', $v);
                            })
                            ->thenInvalid('Capability must be in dot notation (e.g., admin.dashboard)')
                        ->end()
                    ->end()
                    ->defaultValue([])
                    ->info('Extension capabilities')
                ->end()
                ->arrayNode('assets')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('templates')
                            ->defaultNull()
                            ->info('Templates directory')
                        ->end()
                        ->scalarNode('public')
                            ->defaultNull()
                            ->info('Public assets directory')
                        ->end()
                        ->scalarNode('config')
                            ->defaultNull()
                            ->info('Configuration files location')
                        ->end()
                        ->scalarNode('migrations')
                            ->defaultNull()
                            ->info('Database migrations directory')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('icon')
                    ->defaultNull()
                    ->info('Extension icon path')
                ->end()
                ->arrayNode('galleryBanner')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('color')
                            ->validate()
                                ->ifTrue(function ($v) {
                                    return !empty($v) && !preg_match('/^#[0-9A-Fa-f]{6}$/', $v);
                                })
                                ->thenInvalid('Gallery banner color must be a valid hex color')
                            ->end()
                            ->info('Gallery banner background color')
                        ->end()
                        ->enumNode('theme')
                            ->values(['light', 'dark'])
                            ->defaultValue('dark')
                            ->info('Gallery banner theme')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('repository')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('type')
                            ->values(['git', 'svn', 'hg'])
                            ->defaultValue('git')
                            ->info('Repository type')
                        ->end()
                        ->scalarNode('url')
                            ->validate()
                                ->ifTrue(function ($v) {
                                    return !empty($v) && !filter_var($v, FILTER_VALIDATE_URL);
                                })
                                ->thenInvalid('Repository URL must be a valid URL')
                            ->end()
                            ->info('Repository URL')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('support')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('documentation')
                            ->validate()
                                ->ifTrue(function ($v) {
                                    return !empty($v) && !filter_var($v, FILTER_VALIDATE_URL);
                                })
                                ->thenInvalid('Documentation URL must be a valid URL')
                            ->end()
                            ->info('Documentation URL')
                        ->end()
                        ->scalarNode('issues')
                            ->validate()
                                ->ifTrue(function ($v) {
                                    return !empty($v) && !filter_var($v, FILTER_VALIDATE_URL);
                                })
                                ->thenInvalid('Issues URL must be a valid URL')
                            ->end()
                            ->info('Issues/bug tracker URL')
                        ->end()
                        ->scalarNode('email')
                            ->validate()
                                ->ifTrue(function ($v) {
                                    return !empty($v) && !filter_var($v, FILTER_VALIDATE_EMAIL);
                                })
                                ->thenInvalid('Support email must be a valid email address')
                            ->end()
                            ->info('Support email')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('compatibility')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('environments')
                            ->enumPrototype()
                                ->values(['production', 'development', 'testing', 'staging'])
                            ->end()
                            ->defaultValue(['production', 'development'])
                            ->info('Compatible environments')
                        ->end()
                        ->arrayNode('platforms')
                            ->enumPrototype()
                                ->values(['linux', 'macos', 'windows'])
                            ->end()
                            ->defaultValue(['linux', 'macos', 'windows'])
                            ->info('Compatible platforms')
                        ->end()
                        ->arrayNode('databases')
                            ->enumPrototype()
                                ->values(['MySQL', 'PostgreSQL', 'SQLite', 'MariaDB'])
                            ->end()
                            ->defaultValue([])
                            ->info('Compatible databases')
                        ->end()
                        ->arrayNode('browsers')
                            ->enumPrototype()
                                ->values(['Chrome', 'Firefox', 'Safari', 'Edge'])
                            ->end()
                            ->defaultValue([])
                            ->info('Compatible browsers (for UI extensions)')
                        ->end()
                    ->end()
                ->end()
                ->enumNode('type')
                    ->values(['core', 'optional', 'dev'])
                    ->defaultValue('optional')
                    ->info('Extension type')
                ->end()
                ->arrayNode('permissions')
                    ->scalarPrototype()
                        ->validate()
                            ->ifTrue(function ($v) {
                                return !preg_match('/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)*$/', $v);
                            })
                            ->thenInvalid('Permission must be in dot notation')
                        ->end()
                    ->end()
                    ->defaultValue([])
                    ->info('Required permissions')
                ->end()
            ->end()
            ->validate()
                ->ifTrue(function ($v) {
                    return !empty($v['dependencies']['extensions']) &&
                           in_array($v['id'], $v['dependencies']['extensions']);
                })
                ->thenInvalid('Extension cannot depend on itself')
            ->end()
            ->validate()
                ->ifTrue(function ($v) {
                    return $v['manifestVersion'] === '2.0' && empty($v['main_class']);
                })
                ->thenInvalid('Manifest version 2.0 requires main_class to be specified')
            ->end()
            ->validate()
                ->ifTrue(function ($v) {
                    return !empty($v['autoload']['psr-4']) && empty($v['main_class']);
                })
                ->thenInvalid('PSR-4 autoload requires main_class to be specified')
            ->end();

        return $treeBuilder;
    }

    public function getConfigurationName(): string
    {
        return 'extension_manifest';
    }

    public function getExtensionName(): string
    {
        return 'core';
    }

    public function getExtensionVersion(): string
    {
        return '1.0.0';
    }

    public function getSupportedManifestVersions(): array
    {
        return ['1.0', '2.0'];
    }

    public function getDescription(): string
    {
        return 'Extension manifest.json schema validation';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }
}
