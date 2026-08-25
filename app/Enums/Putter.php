<?php

namespace App\Enums;

enum Putter: string
{
    case Blade = 'blade';
    case Mallet = 'mallet';

    public function label(): string
    {
        return match ($this) {
            self::Blade => 'Blade',
            self::Mallet => 'Mallet',
        };
    }

    /**
     * The putter assumed when a client syncs a putt without one, which happens
     * whenever a phone posts from a queue built by a bundle older than this feature.
     */
    public static function default(): self
    {
        return self::Blade;
    }

    public function other(): self
    {
        return $this === self::Blade ? self::Mallet : self::Blade;
    }
}
