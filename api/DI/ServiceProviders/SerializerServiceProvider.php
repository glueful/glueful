<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\ServiceTags;
use Glueful\DI\Container;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Glueful\Serialization\Serializer as GluefulSerializer;
use Glueful\Security\SecureSerializer;

/**
 * Serializer Service Provider
 *
 * Registers Symfony Serializer with the dependency injection container.
 * Configures the serializer with JSON/XML encoders and normalizers
 * for common PHP types including DateTime and arrays.
 *
 * @package Glueful\DI\ServiceProviders
 */
class SerializerServiceProvider implements ServiceProviderInterface
{
    /**
     * Register serializer services in Symfony ContainerBuilder
     */
    public function register(ContainerBuilder $container): void
    {
        // Register Symfony Serializer
        $container->register(SerializerInterface::class)
            ->setFactory([$this, 'createSerializer'])
            ->setPublic(true)
            ->addTag(ServiceTags::SERIALIZER_NORMALIZER);

        // Register Glueful Serializer wrapper
        $container->register(GluefulSerializer::class)
            ->setArguments([
                new Reference(SerializerInterface::class),
                new Reference(SecureSerializer::class)
            ])
            ->setPublic(true);

        // Register SecureSerializer
        $container->register(SecureSerializer::class)
            ->setPublic(true);
    }

    /**
     * Boot serializer services after container is built
     */
    public function boot(Container $container): void
    {
        // Serializer is ready to use after registration
        // No additional boot configuration needed
    }

    /**
     * Get compiler passes for serializer services
     */
    public function getCompilerPasses(): array
    {
        return [
            // Serializer normalizers will be processed by TaggedServicePass
        ];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'serializer';
    }

    /**
     * Factory method for creating Symfony Serializer
     */
    public static function createSerializer(): SerializerInterface
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());

        $normalizers = [
            new DateTimeNormalizer(),
            new ArrayDenormalizer(),
            new ObjectNormalizer($classMetadataFactory),
        ];

        $encoders = [
            new JsonEncoder(),
            new XmlEncoder(),
        ];

        return new Serializer($normalizers, $encoders);
    }
}
