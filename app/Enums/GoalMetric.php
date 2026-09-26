<?php

namespace App\Enums;

/**
 * What a challenge goal counts.
 */
enum GoalMetric: string
{
    case Attempts = 'attempts';
    case Makes = 'makes';
    case MakePercent = 'make_percent';
    case MakeStreak = 'make_streak';
    case DaysPractised = 'days_practised';
    case DrillRunsCompleted = 'drill_runs_completed';

    public function label(): string
    {
        return match ($this) {
            self::Attempts => 'Putts',
            self::Makes => 'Makes',
            self::MakePercent => 'Make %',
            self::MakeStreak => 'Makes in a row',
            self::DaysPractised => 'Days practised',
            self::DrillRunsCompleted => 'Drills completed',
        };
    }
}
