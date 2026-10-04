<?php

namespace App\Enums;

/**
 * What failing a step — missing more than its attempts allow — does to your
 * place in a drill. With one attempt per step, that is any miss.
 */
enum DrillMissRule: string
{
    /** The classic ladder: a failed step sends you back to the first step. */
    case Restart = 'restart';

    /** Stay on the current step and go again until you clear it. */
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
