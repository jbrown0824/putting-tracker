<?php

namespace App\Enums;

enum PuttContext: string
{
    /**
     * The query-string value meaning "do not filter by context".
     *
     * Because the context filter is sticky, clearing it needs a value of its own —
     * simply omitting the parameter is indistinguishable from not asking, which
     * leaves the remembered filter in place.
     */
    public const ANY = 'all';

    case Inside = 'inside';
    case Outside = 'outside';

    public function label(): string
    {
        return match ($this) {
            self::Inside => 'Inside',
            self::Outside => 'Outside',
        };
    }
}
