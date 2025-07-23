<?php

declare(strict_types=1);

namespace Glueful\Configuration\Schema;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;

/**
 * HTTP Client Configuration Schema
 *
 * Defines validation rules and structure for HTTP client settings including
 * timeouts, SSL verification, retry policies, and scoped client configurations.
 *
 * @package Glueful\Configuration\Schema
 */
class HttpConfiguration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('http');
        $rootNode = $treeBuilder->getRootNode();

        // @phpstan-ignore-next-line TreeBuilder root nodes are ArrayNodeDefinitions in practice
        $rootNode
            ->children()
                ->arrayNode('default')
                    ->info('Default HTTP client configuration')
                    ->children()
                        ->integerNode('timeout')
                            ->defaultValue(30)
                            ->min(1)
                            ->max(600)
                            ->info('Request timeout in seconds')
                        ->end()
                        ->integerNode('max_duration')
                            ->defaultValue(60)
                            ->min(1)
                            ->max(1200)
                            ->info('Maximum request duration in seconds')
                        ->end()
                        ->integerNode('max_redirects')
                            ->defaultValue(3)
                            ->min(0)
                            ->max(20)
                            ->info('Maximum number of redirects to follow')
                        ->end()
                        ->enumNode('http_version')
                            ->values(['1.0', '1.1', '2.0'])
                            ->defaultValue('2.0')
                            ->info('HTTP protocol version')
                        ->end()
                        ->booleanNode('verify_ssl')
                            ->defaultValue(true)
                            ->info('Enable SSL certificate verification')
                        ->end()
                        ->scalarNode('user_agent')
                            ->defaultValue('Glueful/1.0')
                            ->info('Default User-Agent header')
                        ->end()
                        ->arrayNode('default_headers')
                            ->info('Default headers for all requests')
                            ->useAttributeAsKey('name')
                            ->scalarPrototype()->end()
                            ->defaultValue(['Accept' => 'application/json'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('retry')
                    ->info('Retry mechanism configuration')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultValue(true)
                            ->info('Enable automatic retries')
                        ->end()
                        ->integerNode('max_retries')
                            ->defaultValue(3)
                            ->min(0)
                            ->max(10)
                            ->info('Maximum number of retry attempts')
                        ->end()
                        ->integerNode('delay_ms')
                            ->defaultValue(1000)
                            ->min(100)
                            ->max(30000)
                            ->info('Initial delay between retries in milliseconds')
                        ->end()
                        ->floatNode('multiplier')
                            ->defaultValue(2.0)
                            ->min(1.0)
                            ->max(5.0)
                            ->info('Multiplier for exponential backoff')
                        ->end()
                        ->integerNode('max_delay_ms')
                            ->defaultValue(30000)
                            ->min(1000)
                            ->max(300000)
                            ->info('Maximum delay between retries in milliseconds')
                        ->end()
                        ->arrayNode('status_codes')
                            ->info('HTTP status codes that trigger retries')
                            ->integerPrototype()
                                ->min(400)
                                ->max(599)
                            ->end()
                            ->defaultValue([423, 425, 429, 500, 502, 503, 504, 507, 510])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('scoped_clients')
                    ->info('Pre-configured scoped clients for specific use cases')
                    ->children()
                        ->arrayNode('oauth')
                            ->info('OAuth client configuration')
                            ->children()
                                ->integerNode('timeout')
                                    ->defaultValue(10)
                                    ->min(1)
                                    ->max(60)
                                ->end()
                                ->arrayNode('headers')
                                    ->useAttributeAsKey('name')
                                    ->scalarPrototype()->end()
                                    ->defaultValue([
                                        'Accept' => 'application/json',
                                        'Content-Type' => 'application/json'
                                    ])
                                ->end()
                            ->end()
                        ->end()
                        ->arrayNode('webhook')
                            ->info('Webhook delivery client configuration')
                            ->children()
                                ->integerNode('timeout')
                                    ->defaultValue(5)
                                    ->min(1)
                                    ->max(30)
                                ->end()
                                ->integerNode('max_redirects')
                                    ->defaultValue(0)
                                    ->min(0)
                                    ->max(5)
                                ->end()
                                ->arrayNode('headers')
                                    ->useAttributeAsKey('name')
                                    ->scalarPrototype()->end()
                                    ->defaultValue([
                                        'Content-Type' => 'application/json',
                                        'User-Agent' => 'Glueful-Webhook/1.0'
                                    ])
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('logging')
                    ->info('HTTP client logging configuration')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultValue(false)
                            ->info('Enable HTTP request/response logging')
                        ->end()
                        ->booleanNode('log_requests')
                            ->defaultValue(true)
                            ->info('Log outgoing requests')
                        ->end()
                        ->booleanNode('log_responses')
                            ->defaultValue(true)
                            ->info('Log incoming responses')
                        ->end()
                        ->booleanNode('log_body')
                            ->defaultValue(false)
                            ->info('Log request/response bodies (security sensitive)')
                        ->end()
                        ->integerNode('slow_threshold_ms')
                            ->defaultValue(5000)
                            ->min(100)
                            ->max(60000)
                            ->info('Threshold for logging slow requests in milliseconds')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    public function getConfigurationName(): string
    {
        return 'http';
    }

    public function getDescription(): string
    {
        return 'HTTP client configuration including timeouts, SSL settings, retry policies, and scoped clients';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }
}
