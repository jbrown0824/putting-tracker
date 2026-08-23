<?php

namespace App\Enums;

enum PuttSlope: string
{
    case Uphill = 'uphill';
    case Downhill = 'downhill';
    case Flat = 'flat';

    public function label(): string
    {
        return match ($this) {
            self::Uphill => 'Uphill',
            self::Downhill => 'Downhill',
            self::Flat => 'Flat',
        };
    }
}
