<?php

namespace App\Enums;

enum DrillOrder: string
{
    case Sequential = 'sequential';
    case Random = 'random';

    public function label(): string
    {
        return match ($this) {
            self::Sequential => 'In order',
            self::Random => 'Random',
        };
    }
}
