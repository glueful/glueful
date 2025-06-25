<?php

declare(strict_types=1);

namespace Glueful\Lock;

interface LockManagerInterface
{
    public function createLock(string $resource, ?float $ttl = null, ?bool $autoRelease = true): LockInterface;

    public function acquireLock(string $resource, ?float $ttl = null): bool;

    public function releaseLock(string $resource): bool;

    public function isLocked(string $resource): bool;

    public function executeWithLock(string $resource, callable $callback, ?float $ttl = null): mixed;

    public function waitAndExecute(
        string $resource,
        callable $callback,
        float $maxWait = 10.0,
        ?float $ttl = null
    ): mixed;
}
