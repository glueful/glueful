<?php

declare(strict_types=1);

namespace Glueful\Serialization\Collections;

use Glueful\Serialization\Attributes\Groups;

/**
 * Serializable Collection
 *
 * A collection wrapper that provides serialization metadata and
 * group-based control over collection items and metadata.
 */
class SerializableCollection implements \JsonSerializable, \Countable, \IteratorAggregate
{
    protected array $items = [];
    protected ?string $defaultGroup = null;
    protected array $metadata = [];

    /**
     * Constructor
     *
     * @param array $items Collection items
     * @param string|null $defaultGroup Default serialization group
     */
    public function __construct(array $items = [], ?string $defaultGroup = null)
    {
        $this->items = $items;
        $this->defaultGroup = $defaultGroup;
    }

    /**
     * Create collection with items
     */
    public static function create(array $items, ?string $defaultGroup = null): self
    {
        return new self($items, $defaultGroup);
    }

    /**
     * Get total count
     */
    #[Groups(['collection:meta', 'collection:summary'])]
    public function getTotal(): int
    {
        return count($this->items);
    }

    /**
     * Get items
     */
    #[Groups(['collection:items', 'collection:full'])]
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Get metadata
     */
    #[Groups(['collection:meta', 'collection:full'])]
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Add metadata
     */
    public function withMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Add multiple metadata
     */
    public function withMetadataArray(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);
        return $this;
    }

    /**
     * Add item to collection
     */
    public function add(mixed $item): self
    {
        $this->items[] = $item;
        return $this;
    }

    /**
     * Add multiple items
     */
    public function addMultiple(array $items): self
    {
        $this->items = array_merge($this->items, $items);
        return $this;
    }

    /**
     * Filter items
     */
    public function filter(callable $callback): self
    {
        $filtered = array_filter($this->items, $callback);
        return new self(array_values($filtered), $this->defaultGroup);
    }

    /**
     * Map items
     */
    public function map(callable $callback): self
    {
        $mapped = array_map($callback, $this->items);
        return new self($mapped, $this->defaultGroup);
    }

    /**
     * Sort items
     */
    public function sort(callable $callback): self
    {
        $items = $this->items;
        usort($items, $callback);
        return new self($items, $this->defaultGroup);
    }

    /**
     * Get first item
     */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    /**
     * Get last item
     */
    public function last(): mixed
    {
        return $this->items[count($this->items) - 1] ?? null;
    }

    /**
     * Check if empty
     */
    #[Groups(['collection:meta', 'collection:summary'])]
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Get item at index
     */
    public function get(int $index): mixed
    {
        return $this->items[$index] ?? null;
    }

    /**
     * Slice collection
     */
    public function slice(int $offset, ?int $length = null): self
    {
        $sliced = array_slice($this->items, $offset, $length);
        return new self($sliced, $this->defaultGroup);
    }

    /**
     * Chunk collection
     */
    public function chunk(int $size): array
    {
        $chunks = array_chunk($this->items, $size);
        return array_map(
            fn($chunk) => new self($chunk, $this->defaultGroup),
            $chunks
        );
    }

    /**
     * Get unique items
     */
    public function unique(): self
    {
        $unique = array_unique($this->items, SORT_REGULAR);
        return new self(array_values($unique), $this->defaultGroup);
    }

    /**
     * Reduce collection
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Group by key
     */
    public function groupBy(string|callable $key): array
    {
        $grouped = [];

        foreach ($this->items as $item) {
            if (is_callable($key)) {
                $groupKey = $key($item);
            } elseif (is_object($item)) {
                $groupKey = $item->$key ?? 'undefined';
            } elseif (is_array($item)) {
                $groupKey = $item[$key] ?? 'undefined';
            } else {
                $groupKey = 'undefined';
            }

            if (!isset($grouped[$groupKey])) {
                $grouped[$groupKey] = new self([], $this->defaultGroup);
            }

            $grouped[$groupKey]->add($item);
        }

        return $grouped;
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * JSON serialization
     */
    public function jsonSerialize(): array
    {
        $data = [
            'items' => $this->items,
            'total' => $this->getTotal(),
        ];

        if (!empty($this->metadata)) {
            $data['metadata'] = $this->metadata;
        }

        return $data;
    }

    /**
     * Count implementation
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Iterator implementation
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    /**
     * Get summary for serialization
     */
    #[Groups(['collection:summary'])]
    public function getSummary(): array
    {
        return [
            'total' => $this->getTotal(),
            'is_empty' => $this->isEmpty(),
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Create paginated collection
     */
    public static function paginated(
        array $items,
        int $page,
        int $perPage,
        int $total
    ): self {
        $collection = new self($items);
        return $collection
            ->withMetadata('page', $page)
            ->withMetadata('per_page', $perPage)
            ->withMetadata('total', $total)
            ->withMetadata('total_pages', (int) ceil($total / $perPage))
            ->withMetadata('has_more_pages', $page < ceil($total / $perPage));
    }
}
