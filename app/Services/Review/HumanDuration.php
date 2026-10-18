<?php

namespace App\Services\Review;

/**
 * Presentation-only duration humanization for the weekly summary (for example "3 h 12 min").
 * The stored KPI definition stays seconds; the exact value is shown via tooltip.
 */
final class HumanDuration
{
    public function format(int $seconds): string
    {
        return match (true) {
            $seconds >= 3600 => __('review.duration.hours_minutes', ['h' => intdiv($seconds, 3600), 'm' => intdiv($seconds % 3600, 60)]),
            $seconds >= 60 => __('review.duration.minutes_seconds', ['m' => intdiv($seconds, 60), 's' => $seconds % 60]),
            default => __('review.duration.seconds_only', ['s' => $seconds]),
        };
    }
}
