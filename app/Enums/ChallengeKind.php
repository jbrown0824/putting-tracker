<?php

namespace App\Enums;

enum ChallengeKind: string
{
    /** Log freely; every matching putt counts towards the challenge's goals. */
    case Goals = 'goals';

    /** Guided: the logger tells you where to putt from, one step at a time. */
    case Drill = 'drill';

    public function label(): string
    {
        return match ($this) {
            self::Goals => 'Goals',
            self::Drill => 'Drill',
        };
    }
}
