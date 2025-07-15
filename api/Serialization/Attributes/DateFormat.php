<?php

declare(strict_types=1);

namespace Glueful\Serialization\Attributes;

/**
 * DateFormat Attribute
 *
 * Glueful attribute for specifying custom date formats during serialization.
 * This attribute works with the DateTimeNormalizer to format DateTime objects.
 *
 * @package Glueful\Serialization\Attributes
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class DateFormat
{
    /**
     * Constructor
     *
     * @param string $format Date format string (PHP date format)
     * @param string|null $timezone Optional timezone for the date
     */
    public function __construct(
        public string $format = \DateTime::ATOM,
        public ?string $timezone = null
    ) {
    }

    /**
     * Get the date format
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Get the timezone
     */
    public function getTimezone(): ?string
    {
        return $this->timezone;
    }
}
