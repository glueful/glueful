<?php

declare(strict_types=1);

namespace Glueful\Serialization\Cache;

/**
 * Hierarchical Cache Key Strategy
 *
 * Creates hierarchical cache keys for better organization
 */
class HierarchicalCacheKeyStrategy implements CacheKeyStrategyInterface
{
    public function generateKey(object $object, array $context): string
    {
        $className = get_class($object);
        $namespace = str_replace('\\', ':', $className);
        $contextHash = md5(serialize($context));

        return $namespace . ':' . $contextHash;
    }
}
