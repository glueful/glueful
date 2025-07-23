<?php

declare(strict_types=1);

namespace Glueful\Serialization\Attributes;

/**
 * SerializeIf Attribute
 *
 * Allows conditional serialization of properties based on runtime conditions.
 * The property will only be serialized if the condition evaluates to true.
 *
 * @package Glueful\Serialization\Attributes
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
class SerializeIf
{
    /**
     * Constructor
     *
     * @param string $condition Property or method name to check
     * @param mixed $value Expected value for condition to be true (optional)
     * @param bool $checkMethod Whether to check a method instead of property
     */
    public function __construct(
        public string $condition,
        public mixed $value = true,
        public bool $checkMethod = false
    ) {
    }

    /**
     * Evaluate condition on object
     */
    public function shouldSerialize(object $object): bool
    {
        if ($this->checkMethod) {
            // Check method
            if (!method_exists($object, $this->condition)) {
                return false;
            }

            $result = $object->{$this->condition}();
            return $result === $this->value;
        } else {
            // Check property
            if (!property_exists($object, $this->condition)) {
                return false;
            }

            // Use reflection to access private/protected properties
            $reflection = new \ReflectionClass($object);
            $property = $reflection->getProperty($this->condition);
            $property->setAccessible(true);

            $actualValue = $property->getValue($object);

            // If value is null, just check if property has a value
            if ($this->value === null) {
                return $actualValue !== null;
            }

            return $actualValue === $this->value;
        }
    }

    /**
     * Get condition name
     */
    public function getCondition(): string
    {
        return $this->condition;
    }

    /**
     * Get expected value
     */
    public function getExpectedValue(): mixed
    {
        return $this->value;
    }
}
