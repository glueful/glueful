<?php

declare(strict_types=1);

namespace Glueful\DI\Passes;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class ExtensionServicePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->processExtensionServices($container);
        $this->processExtensionProviders($container);
        $this->validateExtensionDependencies($container);
    }

    private function processExtensionServices(ContainerBuilder $container): void
    {
        if (!$container->has('extension.manager')) {
            return;
        }

        $definition = $container->findDefinition('extension.manager');
        $taggedServices = $container->findTaggedServiceIds('extension.service');

        foreach ($taggedServices as $id => $tags) {
            foreach ($tags as $attributes) {
                $extensionName = $attributes['extension'] ?? null;
                if ($extensionName) {
                    $definition->addMethodCall('registerExtensionService', [
                        $extensionName,
                        $id,
                        new Reference($id)
                    ]);
                }
            }
        }
    }

    private function processExtensionProviders(ContainerBuilder $container): void
    {
        if (!$container->has('extension.manager')) {
            return;
        }

        $definition = $container->findDefinition('extension.manager');
        $taggedServices = $container->findTaggedServiceIds('extension.provider');

        foreach ($taggedServices as $id => $tags) {
            foreach ($tags as $attributes) {
                $extensionName = $attributes['extension'] ?? null;
                if ($extensionName) {
                    $definition->addMethodCall('registerExtensionProvider', [
                        $extensionName,
                        new Reference($id)
                    ]);
                }
            }
        }
    }

    private function validateExtensionDependencies(ContainerBuilder $container): void
    {
        // Validate that all extension services have their dependencies met
        $extensionServices = $container->findTaggedServiceIds('extension.service');

        foreach ($extensionServices as $id => $tags) {
            foreach ($tags as $attributes) {
                $dependencies = $attributes['dependencies'] ?? [];

                foreach ($dependencies as $dependency) {
                    if (!$container->has($dependency)) {
                        throw new \RuntimeException(
                            "Extension service '{$id}' requires dependency '{$dependency}' which is not registered"
                        );
                    }
                }
            }
        }
    }
}
