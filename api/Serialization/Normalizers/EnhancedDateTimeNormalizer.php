<?php

declare(strict_types=1);

namespace Glueful\Serialization\Normalizers;

use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Enhanced DateTime Normalizer
 *
 * Extends Symfony's DateTimeNormalizer with support for:
 * - Custom DateFormat attributes
 * - Timezone conversion
 * - Locale-specific formatting
 * - Multiple output formats
 */
class EnhancedDateTimeNormalizer implements NormalizerInterface, DenormalizerInterface
{
    private array $timezones = [];
    private ?string $defaultTimezone = null;

    /**
     * Constructor
     */
    public function __construct(
        ?string $defaultTimezone = null
    ) {
        $this->defaultTimezone = $defaultTimezone ?? date_default_timezone_get();
    }

    /**
     * Normalize DateTime with enhanced features
     */
    public function normalize(
        mixed $object,
        ?string $format = null,
        array $context = []
    ): array|string|int|float|bool|\ArrayObject|null {
        if (!$object instanceof \DateTimeInterface) {
            throw new \InvalidArgumentException('Object must implement DateTimeInterface');
        }

        // Check for DateFormat attribute in the current property context
        $customFormat = $this->getCustomFormat($context);
        $timezone = $this->getTargetTimezone($context);

        // Clone the datetime to avoid modifying the original
        $dateTime = clone $object;

        // Convert timezone if specified
        if ($timezone) {
            if ($dateTime instanceof \DateTime) {
                $dateTime->setTimezone(new \DateTimeZone($timezone));
            } elseif ($dateTime instanceof \DateTimeImmutable) {
                $dateTime = $dateTime->setTimezone(new \DateTimeZone($timezone));
            }
        }

        // Use custom format if available
        if ($customFormat) {
            return $dateTime->format($customFormat);
        }

        // Check context for format
        $formatString = $context['datetime_format'] ?? \DateTime::ATOM;

        return $dateTime->format($formatString);
    }

    /**
     * Extended normalization with multiple formats
     */
    public function normalizeWithFormats(
        \DateTimeInterface $dateTime,
        array $formats = [],
        ?string $timezone = null
    ): array {
        $result = [];

        // Clone to avoid modifying original
        $dt = clone $dateTime;

        // Convert timezone if specified
        if ($timezone) {
            if ($dt instanceof \DateTime) {
                $dt->setTimezone(new \DateTimeZone($timezone));
            } elseif ($dt instanceof \DateTimeImmutable) {
                $dt = $dt->setTimezone(new \DateTimeZone($timezone));
            }
        }

        // Default formats if none provided
        if (empty($formats)) {
            $formats = [
                'iso' => \DateTime::ATOM,
                'human' => 'Y-m-d H:i:s',
                'date' => 'Y-m-d',
                'time' => 'H:i:s',
                'timestamp' => 'U',
            ];
        }

        foreach ($formats as $key => $format) {
            if ($format === 'U') {
                $result[$key] = (int) $dt->format($format);
            } else {
                $result[$key] = $dt->format($format);
            }
        }

        return $result;
    }

    /**
     * Get relative time (time ago)
     */
    public function getRelativeTime(\DateTimeInterface $dateTime, ?\DateTimeInterface $now = null): string
    {
        $now = $now ?: new \DateTime();
        $diff = $now->diff($dateTime);

        if ($diff->days > 365) {
            $years = floor($diff->days / 365);
            return $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
        }

        if ($diff->days > 30) {
            $months = floor($diff->days / 30);
            return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
        }

        if ($diff->days > 0) {
            return $diff->days . ' day' . ($diff->days > 1 ? 's' : '') . ' ago';
        }

        if ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
        }

        if ($diff->i > 0) {
            return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
        }

        return 'just now';
    }

    /**
     * Get custom format from DateFormat attribute
     */
    private function getCustomFormat(array $context): ?string
    {
        // This would be populated by a custom attribute reader
        return $context['date_format'] ?? null;
    }

    /**
     * Get target timezone from context
     */
    private function getTargetTimezone(array $context): ?string
    {
        return $context['target_timezone'] ?? $this->defaultTimezone;
    }

    /**
     * Add timezone mapping
     */
    public function addTimezoneMapping(string $key, string $timezone): self
    {
        $this->timezones[$key] = $timezone;
        return $this;
    }

    /**
     * Set default timezone
     */
    public function setDefaultTimezone(string $timezone): self
    {
        $this->defaultTimezone = $timezone;
        return $this;
    }

    /**
     * Format for specific locale
     */
    public function formatForLocale(
        \DateTimeInterface $dateTime,
        string $locale = 'en_US',
        int $dateType = \IntlDateFormatter::MEDIUM,
        int $timeType = \IntlDateFormatter::SHORT
    ): string {
        if (!class_exists('\IntlDateFormatter')) {
            // Fallback if Intl extension is not available
            return $dateTime->format('Y-m-d H:i:s');
        }

        $formatter = new \IntlDateFormatter(
            $locale,
            $dateType,
            $timeType,
            $dateTime->getTimezone()->getName()
        );

        return $formatter->format($dateTime);
    }

    /**
     * Create business hours aware formatter
     */
    public function formatBusinessHours(
        \DateTimeInterface $dateTime,
        array $businessHours = ['09:00', '17:00'],
        string $timezone = 'UTC'
    ): array {
        $dt = clone $dateTime;
        if ($dt instanceof \DateTime) {
            $dt->setTimezone(new \DateTimeZone($timezone));
        } elseif ($dt instanceof \DateTimeImmutable) {
            $dt = $dt->setTimezone(new \DateTimeZone($timezone));
        }

        $hour = (int) $dt->format('H');
        $minute = (int) $dt->format('i');
        $currentTime = $hour + ($minute / 60);

        $startHour = (float) str_replace(':', '.', str_replace(':', '', $businessHours[0]));
        $endHour = (float) str_replace(':', '.', str_replace(':', '', $businessHours[1]));

        $isBusinessHours = $currentTime >= $startHour && $currentTime <= $endHour;
        $dayOfWeek = (int) $dt->format('N'); // 1 = Monday, 7 = Sunday
        $isWeekday = $dayOfWeek <= 5;

        return [
            'formatted' => $dt->format('Y-m-d H:i:s T'),
            'is_business_hours' => $isBusinessHours && $isWeekday,
            'is_weekday' => $isWeekday,
            'day_of_week' => $dt->format('l'),
            'timezone' => $timezone,
        ];
    }

    /**
     * Denormalize string to DateTime
     */
    public function denormalize(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): \DateTimeInterface {
        if (is_string($data)) {
            return new \DateTime($data);
        }

        if (is_array($data) && isset($data['formatted'])) {
            return new \DateTime($data['formatted']);
        }

        throw new \InvalidArgumentException('Cannot denormalize data to DateTime');
    }

    /**
     * Check if normalization is supported
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof \DateTimeInterface;
    }

    /**
     * Check if denormalization is supported
     */
    public function supportsDenormalization(
        mixed $data,
        string $type,
        ?string $format = null,
        array $context = []
    ): bool {
        return is_subclass_of($type, \DateTimeInterface::class)
            || $type === \DateTime::class
            || $type === \DateTimeImmutable::class;
    }

    /**
     * Get supported types for this normalizer
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            \DateTime::class => true,
            \DateTimeImmutable::class => true,
            \DateTimeInterface::class => true,
        ];
    }
}
