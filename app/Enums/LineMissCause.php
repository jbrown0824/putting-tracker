<?php

namespace App\Enums;

/**
 * Why a putt missed left or right. The two causes need completely different
 * practice, so pooling them into one "line miss" hides the only useful question.
 */
enum LineMissCause: string
{
    /** The ball never started on the intended line — face angle or path. */
    case Stroke = 'stroke';

    /** It started on line and the green did something you did not expect. */
    case Read = 'read';

    public function label(): string
    {
        return match ($this) {
            self::Stroke => 'Stroke',
            self::Read => 'Read',
        };
    }

    /**
     * The golfer's word for this miss, which depends on which way it went.
     *
     * Assumes a right-handed stroke: a pull goes left, a push goes right. Flip the
     * two arms here if this ever needs to serve a left-hander.
     */
    public function labelFor(PuttResult $result): string
    {
        return match ($this) {
            self::Read => 'Misread',
            self::Stroke => $result === PuttResult::MissLeft ? 'Pull' : 'Push',
        };
    }

    public function coaching(): string
    {
        return match ($this) {
            self::Stroke => 'the face is not square at impact — gate drills, not green reading',
            self::Read => 'the stroke is starting it on line — the read is what is costing you',
        };
    }

    /**
     * A cause only means anything on a left or right miss.
     */
    public static function appliesTo(PuttResult $result): bool
    {
        return $result->isLineError();
    }
}
