<?php

declare(strict_types=1);

namespace Glueful\Serialization\Normalizers;

use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Glueful\Serialization\Attributes\SerializeIf;

/**
 * Conditional Normalizer
 *
 * Handles conditional serialization based on SerializeIf attributes.
 * Works as a decorator around the default object normalizer.
 */
class ConditionalNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * Normalize object with conditional serialization
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        // First, get the normal serialization
        $data = $this->normalizer->normalize($object, $format, $context);

        if (!is_array($data) || !is_object($object)) {
            return $data;
        }

        // Check for conditional serialization attributes
        $reflection = new \ReflectionClass($object);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $propertyName = $property->getName();

            // Check if property has SerializeIf attribute
            $attributes = $property->getAttributes(SerializeIf::class);

            foreach ($attributes as $attribute) {
                /** @var SerializeIf $serializeIf */
                $serializeIf = $attribute->newInstance();

                // If condition is not met, remove from serialized data
                if (!$serializeIf->shouldSerialize($object)) {
                    unset($data[$propertyName]);
                }
            }
        }

        // Also check methods for SerializeIf attributes
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $methodName = $method->getName();

            // Skip getters and setters, look for actual serializable methods
            if (strpos($methodName, 'get') === 0 || strpos($methodName, 'set') === 0) {
                continue;
            }

            $attributes = $method->getAttributes(SerializeIf::class);

            foreach ($attributes as $attribute) {
                /** @var SerializeIf $serializeIf */
                $serializeIf = $attribute->newInstance();

                // If condition is not met, don't include method result
                if (!$serializeIf->shouldSerialize($object)) {
                    unset($data[$methodName]);
                }
            }
        }

        return $data;
    }

    /**
     * Check if this normalizer supports the data
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return is_object($data) && $this->hasConditionalAttributes($data);
    }

    /**
     * Get supported types for this normalizer
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            'object' => true,
        ];
    }

    /**
     * Check if object has conditional serialization attributes
     */
    private function hasConditionalAttributes(object $object): bool
    {
        $reflection = new \ReflectionClass($object);

        // Check properties
        foreach ($reflection->getProperties() as $property) {
            if (!empty($property->getAttributes(SerializeIf::class))) {
                return true;
            }
        }

        // Check methods
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!empty($method->getAttributes(SerializeIf::class))) {
                return true;
            }
        }

        return false;
    }
}
