<?php

declare(strict_types=1);

namespace Glueful\Database\Features;

use Glueful\Database\Features\Interfaces\QueryPurposeInterface;

/**
 * Tracks and manages the business purpose of database queries
 *
 * This component helps with auditing, monitoring, and debugging by
 * associating business context with database operations.
 */
class QueryPurpose implements QueryPurposeInterface
{
    private ?string $purpose = null;
    private array $metadata = [];

    /**
     * {@inheritdoc}
     */
    public function setPurpose(string $purpose): void
    {
        $this->purpose = $purpose;
    }

    /**
     * {@inheritdoc}
     */
    public function getPurpose(): ?string
    {
        return $this->purpose;
    }

    /**
     * {@inheritdoc}
     */
    public function clearPurpose(): void
    {
        $this->purpose = null;
        $this->metadata = [];
    }

    /**
     * {@inheritdoc}
     */
    public function hasPurpose(): bool
    {
        return $this->purpose !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function addMetadata(string $key, mixed $value): void
    {
        $this->metadata[$key] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function formatForLogging(): string
    {
        if (!$this->hasPurpose()) {
            return '[No business purpose set]';
        }

        $formatted = "Purpose: {$this->purpose}";

        if (!empty($this->metadata)) {
            $metadataString = json_encode($this->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $formatted .= " | Metadata: {$metadataString}";
        }

        return $formatted;
    }

    /**
     * {@inheritdoc}
     */
    public function getContext(): array
    {
        return [
            'purpose' => $this->purpose,
            'metadata' => $this->metadata,
            'timestamp' => microtime(true),
            'formatted' => $this->formatForLogging()
        ];
    }

    /**
     * Set purpose with automatic metadata
     *
     * @param string $purpose The business purpose
     * @param string|null $userId User ID performing the action
     * @param string|null $requestId Request ID for tracing
     */
    public function setPurposeWithContext(string $purpose, ?string $userId = null, ?string $requestId = null): void
    {
        $this->setPurpose($purpose);

        if ($userId !== null) {
            $this->addMetadata('user_id', $userId);
        }

        if ($requestId !== null) {
            $this->addMetadata('request_id', $requestId);
        }

        $this->addMetadata('set_at', date('Y-m-d H:i:s'));
    }

    /**
     * Create a copy of this purpose instance
     */
    public function clone(): self
    {
        $clone = new self();
        $clone->purpose = $this->purpose;
        $clone->metadata = $this->metadata;
        return $clone;
    }
}
