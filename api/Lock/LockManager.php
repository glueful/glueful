<?php

declare(strict_types=1);

namespace Glueful\Lock;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class LockManager implements LockManagerInterface
{
    private LockFactory $factory;
    private array $locks = [];
    private LoggerInterface $logger;
    private string $prefix;

    public function __construct(
        PersistingStoreInterface $store,
        ?LoggerInterface $logger = null,
        string $prefix = 'glueful_lock_'
    ) {
        $this->factory = new LockFactory($store);
        $this->logger = $logger ?? new NullLogger();
        $this->prefix = $prefix;
    }

    public function createLock(string $resource, ?float $ttl = null, ?bool $autoRelease = true): LockInterface
    {
        $key = $this->prefix . $resource;
        $symfonyLock = $this->factory->createLock($key, $ttl, $autoRelease);

        $lock = new Lock($symfonyLock, $resource, $ttl);
        $this->locks[$resource] = $lock;

        $this->logger->debug('Lock created', [
            'resource' => $resource,
            'ttl' => $ttl,
            'autoRelease' => $autoRelease
        ]);

        return $lock;
    }

    public function acquireLock(string $resource, ?float $ttl = null): bool
    {
        try {
            $lock = $this->createLock($resource, $ttl);
            $acquired = $lock->acquire();

            $this->logger->info('Lock acquisition attempt', [
                'resource' => $resource,
                'acquired' => $acquired,
                'ttl' => $ttl
            ]);

            return $acquired;
        } catch (LockConflictedException $e) {
            $this->logger->warning('Lock conflict', [
                'resource' => $resource,
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function releaseLock(string $resource): bool
    {
        if (!isset($this->locks[$resource])) {
            $this->logger->warning('Attempting to release non-existent lock', [
                'resource' => $resource
            ]);
            return false;
        }

        try {
            $lock = $this->locks[$resource];
            if ($lock->isAcquired()) {
                $lock->release();
                unset($this->locks[$resource]);

                $this->logger->info('Lock released', [
                    'resource' => $resource
                ]);

                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->logger->error('Failed to release lock', [
                'resource' => $resource,
                'exception' => $e->getMessage()
            ]);
            return false;
        }
    }

    public function isLocked(string $resource): bool
    {
        if (isset($this->locks[$resource])) {
            return $this->locks[$resource]->isAcquired();
        }

        // Check if resource is locked by another process
        $lock = $this->createLock($resource, 0.1);
        $canAcquire = $lock->acquire();

        if ($canAcquire) {
            $lock->release();
            return false;
        }

        return true;
    }

    public function executeWithLock(string $resource, callable $callback, ?float $ttl = null): mixed
    {
        $lock = $this->createLock($resource, $ttl);

        if (!$lock->acquire()) {
            throw new LockConflictedException(sprintf('Could not acquire lock for resource "%s"', $resource));
        }

        try {
            $this->logger->info('Executing callback with lock', [
                'resource' => $resource
            ]);

            return $callback();
        } finally {
            $lock->release();
            unset($this->locks[$resource]);
        }
    }

    public function waitAndExecute(
        string $resource,
        callable $callback,
        float $maxWait = 10.0,
        ?float $ttl = null
    ): mixed {
        $lock = $this->createLock($resource, $ttl);
        $startTime = microtime(true);

        while (!$lock->acquire()) {
            if (microtime(true) - $startTime > $maxWait) {
                throw new LockConflictedException(sprintf(
                    'Could not acquire lock for resource "%s" within %s seconds',
                    $resource,
                    $maxWait
                ));
            }

            usleep(100000); // Sleep for 100ms
        }

        try {
            $this->logger->info('Executing callback with lock after wait', [
                'resource' => $resource,
                'waitTime' => microtime(true) - $startTime
            ]);

            return $callback();
        } finally {
            $lock->release();
            unset($this->locks[$resource]);
        }
    }

    public function __destruct()
    {
        // Release all locks on destruction
        foreach ($this->locks as $resource => $lock) {
            if ($lock->isAcquired()) {
                $this->logger->warning('Auto-releasing lock on destruction', [
                    'resource' => $resource
                ]);
                $lock->release();
            }
        }
    }
}
