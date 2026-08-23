<?php

namespace App\Enums;

enum PuttContext: string
{
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
