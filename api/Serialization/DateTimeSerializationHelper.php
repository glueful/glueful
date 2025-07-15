<?php

declare(strict_types=1);

namespace Glueful\Serialization;

/**
 * DateTime Helper Service
 *
 * Provides additional datetime utilities for serialization
 */
class DateTimeSerializationHelper
{
    /**
     * Create datetime range representation
     */
    public static function normalizeRange(
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        string $format = \DateTime::ATOM
    ): array {
        $duration = $end->diff($start);

        return [
            'start' => $start->format($format),
            'end' => $end->format($format),
            'duration' => [
                'total_seconds' => $duration->s + ($duration->i * 60) + ($duration->h * 3600)
                    + ($duration->days * 86400),
                'human' => self::formatDuration($duration),
                'days' => $duration->days,
                'hours' => $duration->h,
                'minutes' => $duration->i,
                'seconds' => $duration->s,
            ],
        ];
    }

    /**
     * Format duration in human-readable format
     */
    public static function formatDuration(\DateInterval $interval): string
    {
        $parts = [];

        if ($interval->days > 0) {
            $parts[] = $interval->days . ' day' . ($interval->days > 1 ? 's' : '');
        }

        if ($interval->h > 0) {
            $parts[] = $interval->h . ' hour' . ($interval->h > 1 ? 's' : '');
        }

        if ($interval->i > 0) {
            $parts[] = $interval->i . ' minute' . ($interval->i > 1 ? 's' : '');
        }

        if ($interval->s > 0 && empty($parts)) {
            $parts[] = $interval->s . ' second' . ($interval->s > 1 ? 's' : '');
        }

        return empty($parts) ? '0 seconds' : implode(', ', $parts);
    }

    /**
     * Get timezone-aware schedule representation
     */
    public static function normalizeSchedule(
        \DateTimeInterface $dateTime,
        array $timezones = ['UTC', 'America/New_York', 'Europe/London', 'Asia/Tokyo']
    ): array {
        $schedule = [];

        foreach ($timezones as $tz) {
            $dt = clone $dateTime;
            if ($dt instanceof \DateTime) {
                $dt->setTimezone(new \DateTimeZone($tz));
            } elseif ($dt instanceof \DateTimeImmutable) {
                $dt = $dt->setTimezone(new \DateTimeZone($tz));
            }

            $schedule[$tz] = [
                'datetime' => $dt->format(\DateTime::ATOM),
                'local_time' => $dt->format('H:i'),
                'local_date' => $dt->format('Y-m-d'),
                'timezone_name' => $dt->getTimezone()->getName(),
                'offset' => $dt->format('P'),
            ];
        }

        return $schedule;
    }
}
