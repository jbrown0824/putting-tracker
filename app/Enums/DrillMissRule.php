<?php

namespace App\Enums;

/**
 * What a miss does to your place in a drill.
 */
enum DrillMissRule: string
{
    /** The classic ladder: any miss sends you back to the first step. */
    case Restart = 'restart';

    /** Stay on the current step until you make it. */
    case Stay = 'stay';

    /** Drop back one step. */
    case StepBack = 'step_back';

    public function label(): string
    {
        return match ($this) {
            self::Restart => 'Back to the start',
            self::Stay => 'Stay on the step',
            self::StepBack => 'Down one step',
        };
    }
}
