<?php

namespace App\Enums;

/**
 * Which way a putt breaks, seen from behind the ball.
 *
 * Never stored. It is derived from ClockPosition, which already fixes it — holding
 * a second copy on the row would only create something to contradict.
 */
enum BreakSide: string
{
    case RightToLeft = 'right_to_left';

    case LeftToRight = 'left_to_right';

    case Straight = 'straight';

    public function label(): string
    {
        return match ($this) {
            self::RightToLeft => 'breaking R→L',
            self::LeftToRight => 'breaking L→R',
            self::Straight => 'straight',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::RightToLeft => 'R→L',
            self::LeftToRight => 'L→R',
            self::Straight => 'Straight',
        };
    }
}
