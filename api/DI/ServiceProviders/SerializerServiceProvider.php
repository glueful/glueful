<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Interfaces\ContainerInterface;
use Glueful\DI\Interfaces\ServiceProviderInterface;
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
     * Register serializer services with the container
     */
    public function register(ContainerInterface $container): void
    {
        // Register Symfony Serializer
        $container->singleton(SerializerInterface::class, function (ContainerInterface $container) {
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
        });

        // Register Glueful Serializer wrapper
        $container->singleton(GluefulSerializer::class, function (ContainerInterface $container) {
            return new GluefulSerializer(
                $container->get(SerializerInterface::class),
                $container->get(SecureSerializer::class)
            );
        });

        // Register SecureSerializer
        $container->singleton(SecureSerializer::class, function () {
            return new SecureSerializer();
        });
    }

    /**
     * Boot serializer services
     */
    public function boot(ContainerInterface $container): void
    {
        // Serializer is ready to use after registration
        // No additional boot configuration needed
    }
}
