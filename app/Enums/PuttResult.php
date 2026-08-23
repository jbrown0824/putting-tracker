<?php

namespace App\Enums;

enum PuttResult: string
{
    case Sunk = 'sunk';
    case MissShort = 'miss_short';
    case MissLong = 'miss_long';
    case MissLeft = 'miss_left';
    case MissRight = 'miss_right';
    case LipOut = 'lip_out';

    public function label(): string
    {
        return match ($this) {
            self::Sunk => 'Sunk',
            self::MissShort => 'Short',
            self::MissLong => 'Long',
            self::MissLeft => 'Left',
            self::MissRight => 'Right',
            self::LipOut => 'Lip out',
        };
    }

    /**
     * A lip out counts as a miss, but the stroke was good enough to be worth tracking apart.
     */
    public function isMade(): bool
    {
        return $this === self::Sunk;
    }

    /**
     * Short and long misses are distance-control errors.
     */
    public function isSpeedError(): bool
    {
        return in_array($this, [self::MissShort, self::MissLong], true);
    }

    /**
     * Left and right misses are aim, read, or stroke-path errors.
     */
    public function isLineError(): bool
    {
        return in_array($this, [self::MissLeft, self::MissRight], true);
    }
}
