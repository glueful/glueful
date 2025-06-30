<?php

declare(strict_types=1);

namespace Glueful\Events\Traits;

/**
 * Timestampable Trait
 *
 * Adds timestamp functionality to events.
 * Provides creation time and elapsed time tracking.
 */
trait Timestampable
{
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Get the event creation timestamp
     */
    public function getTimestamp(): \DateTimeImmutable
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }

        return $this->createdAt;
    }

    /**
     * Get the event creation time as Unix timestamp
     */
    public function getUnixTimestamp(): int
    {
        return $this->getTimestamp()->getTimestamp();
    }

    /**
     * Get the elapsed time since event creation in seconds
     */
    public function getElapsedTime(): float
    {
        $now = new \DateTimeImmutable();
        $interval = $now->getTimestamp() - $this->getTimestamp()->getTimestamp();

        return (float) $interval;
    }

    /**
     * Check if the event is older than the given seconds
     */
    public function isOlderThan(int $seconds): bool
    {
        return $this->getElapsedTime() > $seconds;
    }

    /**
     * Get a formatted timestamp string
     */
    public function getFormattedTimestamp(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->getTimestamp()->format($format);
    }
}
