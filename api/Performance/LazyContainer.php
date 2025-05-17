<?php

namespace Glueful\Performance;

class LazyContainer
{
    private $factories = [];
    private $instances = [];

    /**
     * Register a factory for lazy loading
     */
    public function register(string $id, \Closure $factory): void
    {
        $this->factories[$id] = $factory;
    }

    /**
     * Get or create an instance
     */
    public function get(string $id)
    {
        if (!isset($this->instances[$id])) {
            if (!isset($this->factories[$id])) {
                throw new \Exception("No factory registered for '{$id}'");
            }

            $this->instances[$id] = ($this->factories[$id])();
        }

        return $this->instances[$id];
    }
}
