<?php

namespace Glueful\Performance;

/**
 * A memory-efficient streaming iterator for large datasets
 */
class StreamingIterator implements \Iterator
{
    private $source;
    private $position = 0;
    private $currentKey;
    private $currentValue;
    private $valid = true;
    private $bufferSize;

    /**
     * Create a new streaming iterator
     *
     * @param \Iterator|\Generator|array|\Traversable $source The data source to stream
     * @param int $bufferSize The buffer size for internal operations
     */
    public function __construct($source, int $bufferSize = 100)
    {
        if (is_array($source)) {
            $this->source = new \ArrayIterator($source);
        } elseif ($source instanceof \Traversable) {
            $this->source = $source;
        } else {
            throw new \InvalidArgumentException('Source must be an array, Iterator, Generator, or Traversable');
        }

        $this->bufferSize = $bufferSize;
        $this->rewind();
    }

    /**
     * Rewind the iterator to the first element
     */
    public function rewind(): void
    {
        if ($this->source instanceof \Iterator) {
            $this->source->rewind();
        }

        $this->position = 0;
        $this->next();
    }

    /**
     * Move to the next element
     */
    public function next(): void
    {
        if ($this->source instanceof \Iterator && $this->source->valid()) {
            $this->currentKey = $this->source->key();
            $this->currentValue = $this->source->current();
            $this->source->next();
            $this->position++;
            $this->valid = true;
        } else {
            $this->valid = false;
        }
    }

    /**
     * Check if the current position is valid
     */
    public function valid(): bool
    {
        return $this->valid;
    }

    /**
     * Get the current element
     */
    public function current(): mixed
    {
        return $this->currentValue;
    }

    /**
     * Get the key of the current element
     */
    public function key(): mixed
    {
        return $this->currentKey;
    }

    /**
     * Create a new instance with a map function applied
     *
     * @param callable $mapFunction Function to apply to each element
     * @return static A new StreamingIterator with the map function applied
     */
    public function map(callable $mapFunction): self
    {
        $generator = function () use ($mapFunction) {
            foreach ($this as $key => $value) {
                yield $key => $mapFunction($value, $key);
            }
        };

        return new self($generator());
    }

    /**
     * Create a new instance with a filter function applied
     *
     * @param callable $filterFunction Function to determine which elements to keep
     * @return static A new StreamingIterator with the filter function applied
     */
    public function filter(callable $filterFunction): self
    {
        $generator = function () use ($filterFunction) {
            foreach ($this as $key => $value) {
                if ($filterFunction($value, $key)) {
                    yield $key => $value;
                }
            }
        };

        return new self($generator());
    }

    /**
     * Process the iterator in chunks
     *
     * @param callable $callback Function to apply to each chunk
     * @param int $chunkSize Size of each chunk
     * @return array Results of applying the callback to each chunk
     */
    public function chunk(callable $callback, int $chunkSize = 1000): array
    {
        return MemoryEfficientIterators::processInChunks($this, $callback, $chunkSize);
    }

    /**
     * Convert the iterator to an array, with chunking to avoid memory issues
     *
     * @param int $maxItems Maximum number of items to convert (0 for all)
     * @return array The iterator contents as an array
     */
    public function toArray(int $maxItems = 0): array
    {
        $result = [];
        $count = 0;

        foreach ($this as $key => $value) {
            $result[$key] = $value;
            $count++;

            if ($maxItems > 0 && $count >= $maxItems) {
                break;
            }
        }

        return $result;
    }
}
