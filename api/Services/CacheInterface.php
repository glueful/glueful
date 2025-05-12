<?php

declare(strict_types=1);

namespace Glueful\ImageProcessing;

/**
 * Cache Interface
 *
 * Defines contract for image caching implementations.
 */
interface CacheInterface
{
    public function get(string $key): ?string;
    public function set(string $key, string $data): bool;
    public function clean(): void;
}
