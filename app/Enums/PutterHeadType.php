<?php

namespace App\Enums;

/**
 * The broad shape of a putter head. Descriptive only — a putter is identified by
 * the row the player created, never by its head type, so owning two mallets is
 * perfectly ordinary.
 */
enum PutterHeadType: string
{
    case Blade = 'blade';
    case Mallet = 'mallet';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Blade => 'Blade',
            self::Mallet => 'Mallet',
            self::Other => 'Other',
        };
    }
}
