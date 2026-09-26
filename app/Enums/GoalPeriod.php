<?php

namespace App\Enums;

/**
 * The window a goal's target applies to. A daily goal is met or missed afresh
 * every day of the challenge; a total goal accumulates across the whole window.
 */
enum GoalPeriod: string
{
    case Total = 'total';
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Total => 'In total',
            self::Daily => 'Per day',
            self::Weekly => 'Per week',
        };
    }
}
