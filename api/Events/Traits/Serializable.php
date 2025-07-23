<?php

declare(strict_types=1);

namespace Glueful\Events\Traits;

/**
 * Serializable Trait
 *
 * Adds serialization support to events for queue/cache storage.
 * Handles proper serialization of event data.
 */
trait Serializable
{
    /**
     * Serialize the event data
     *
     * @return array Serialized event data
     */
    public function __serialize(): array
    {
        $data = [];
        $reflection = new \ReflectionClass($this);

        // Get all properties (public, protected, private)
        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $name = $property->getName();
            $value = $property->getValue($this);

            // Handle special serialization for certain types
            $data[$name] = $this->serializeValue($value);
        }

        return $data;
    }

    /**
     * Unserialize the event data
     *
     * @param array $data Serialized event data
     */
    public function __unserialize(array $data): void
    {
        $reflection = new \ReflectionClass($this);

        foreach ($data as $name => $value) {
            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);
                $property->setAccessible(true);

                // Handle special unserialization for certain types
                $property->setValue($this, $this->unserializeValue($value));
            }
        }
    }

    /**
     * Convert event to array representation
     */
    public function toArray(): array
    {
        return $this->__serialize();
    }

    /**
     * Convert event to JSON string
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Create event instance from array data
     */
    public static function fromArray(array $data): static
    {
        $instance = new static(...[]);
        $instance->__unserialize($data);
        return $instance;
    }

    /**
     * Serialize individual values with special handling
     */
    private function serializeValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return [
                '__type' => 'datetime',
                '__value' => $value->format(\DateTimeInterface::ATOM),
                '__timezone' => $value->getTimezone()->getName()
            ];
        }

        if (is_object($value) && method_exists($value, '__serialize')) {
            return [
                '__type' => 'serializable',
                '__class' => get_class($value),
                '__value' => $value->__serialize()
            ];
        }

        return $value;
    }

    /**
     * Unserialize individual values with special handling
     */
    private function unserializeValue(mixed $value): mixed
    {
        if (is_array($value) && isset($value['__type'])) {
            switch ($value['__type']) {
                case 'datetime':
                    $timezone = new \DateTimeZone($value['__timezone']);
                    return new \DateTimeImmutable($value['__value'], $timezone);

                case 'serializable':
                    $class = $value['__class'];
                    if (class_exists($class)) {
                        $instance = new $class();
                        if (method_exists($instance, '__unserialize')) {
                            $instance->__unserialize($value['__value']);
                            return $instance;
                        }
                    }
                    break;
            }
        }

        return $value;
    }
}
