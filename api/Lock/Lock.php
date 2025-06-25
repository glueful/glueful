<?php

declare(strict_types=1);

namespace Glueful\Lock;

use Symfony\Component\Lock\LockInterface as SymfonyLockInterface;

class Lock implements LockInterface
{
    public function __construct(
        private SymfonyLockInterface $lock,
        private string $key,
        private ?float $ttl = null
    ) {
    }

    public function acquire(bool $blocking = false): bool
    {
        return $this->lock->acquire($blocking);
    }

    public function refresh(?float $ttl = null): void
    {
        $this->lock->refresh($ttl);
    }

    public function isAcquired(): bool
    {
        return $this->lock->isAcquired();
    }

    public function release(): void
    {
        $this->lock->release();
    }

    public function isExpired(): bool
    {
        return $this->lock->isExpired();
    }

    public function getRemainingLifetime(): ?float
    {
        return $this->lock->getRemainingLifetime();
    }

    public function getKey(): string
    {
        return $this->key;
    }
}
