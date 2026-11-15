<?php

declare(strict_types=1);

namespace IndianOpinion\FeaturedFromPrompt;

/**
 * Time source and the site day.
 *
 * The site day is the calendar day in the WordPress timezone setting, so
 * the caps roll over at local midnight, also across daylight-saving changes.
 */
class Clock
{
    public function now(): int
    {
        return time();
    }

    public function day(?int $timestamp = null): string
    {
        $date = (new \DateTimeImmutable('@' . ($timestamp ?? $this->now())))->setTimezone(wp_timezone());
        return $date->format('Y-m-d');
    }

    /** Unix time of the next local midnight after the given time. */
    public function next_day_start(?int $timestamp = null): int
    {
        $date = (new \DateTimeImmutable('@' . ($timestamp ?? $this->now())))->setTimezone(wp_timezone());
        return $date->modify('tomorrow')->getTimestamp();
    }
}
