<?php

declare(strict_types=1);

namespace Glueful\DI\Passes;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class TaggedServicePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->processEventSubscribers($container);
        $this->processMiddleware($container);
        $this->processValidationRules($container);
        $this->processConsoleCommands($container);
    }

    private function processEventSubscribers(ContainerBuilder $container): void
    {
        if (!$container->has('event.dispatcher')) {
            return;
        }

        $definition = $container->findDefinition('event.dispatcher');
        $taggedServices = $container->findTaggedServiceIds('event.subscriber');

        foreach ($taggedServices as $id => $tags) {
            $definition->addMethodCall('addSubscriber', [new Reference($id)]);
        }
    }

    private function processMiddleware(ContainerBuilder $container): void
    {
        if (!$container->has('middleware.stack')) {
            return;
        }

        $definition = $container->findDefinition('middleware.stack');
        $taggedServices = $container->findTaggedServiceIds('middleware');

        $middleware = [];
        foreach ($taggedServices as $id => $tags) {
            foreach ($tags as $attributes) {
                $priority = $attributes['priority'] ?? 0;
                $middleware[$priority][] = new Reference($id);
            }
        }

        // Sort by priority
        krsort($middleware);
        foreach ($middleware as $priorityGroup) {
            foreach ($priorityGroup as $middlewareRef) {
                $definition->addMethodCall('add', [$middlewareRef]);
            }
        }
    }

    private function processValidationRules(ContainerBuilder $container): void
    {
        if (!$container->has('validator')) {
            return;
        }

        $definition = $container->findDefinition('validator');
        $taggedServices = $container->findTaggedServiceIds('validation.rule');

        foreach ($taggedServices as $id => $tags) {
            foreach ($tags as $attributes) {
                $ruleName = $attributes['rule_name'] ?? null;
                if ($ruleName) {
                    $definition->addMethodCall('addRule', [$ruleName, new Reference($id)]);
                }
            }
        }
    }

    private function processConsoleCommands(ContainerBuilder $container): void
    {
        if (!$container->has('console.application')) {
            return;
        }

        $definition = $container->findDefinition('console.application');
        $taggedServices = $container->findTaggedServiceIds('console.command');

        foreach ($taggedServices as $id => $tags) {
            $definition->addMethodCall('add', [new Reference($id)]);
        }
    }
}
