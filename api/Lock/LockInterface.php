<?php

declare(strict_types=1);

namespace Glueful\Lock;

use Symfony\Component\Lock\LockInterface as SymfonyLockInterface;

interface LockInterface extends SymfonyLockInterface
{
    public function getKey(): string;

    public function getRemainingLifetime(): ?float;

    public function isExpired(): bool;
}
