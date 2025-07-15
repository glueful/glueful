<?php

declare(strict_types=1);

namespace Glueful\Validation;

/**
 * Collection Builder
 *
 * Handles validation of array/collection fields.
 */
class CollectionBuilder
{
    /** @var array Field constraints */
    private array $fields;

    /**
     * Constructor
     *
     * @param array $fields Field constraints
     */
    public function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    /**
     * Build collection constraint configuration
     *
     * @return array Collection constraint configuration
     */
    public function build(): array
    {
        return [
            'type' => 'collection',
            'fields' => $this->fields,
        ];
    }
}
