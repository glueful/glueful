<?php

declare(strict_types=1);

namespace Glueful\Serialization\Cache;

/**
 * Default Cache Key Strategy
 */
class DefaultCacheKeyStrategy implements CacheKeyStrategyInterface
{
    public function generateKey(object $object, array $context): string
    {
        return md5(get_class($object) . serialize($context));
    }
}
