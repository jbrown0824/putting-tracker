<?php

namespace App\Services;

use App\Enums\DrillMissRule;
use App\Enums\DrillOrder;
use App\Models\Challenge;
use App\Models\ChallengeStep;

/**
 * Replays a guided drill from the putts hit in it.
 *
 * The phone runs the same rules in resources/js/drill.js to tell the player where
 * to stand next; this is the authority that decides whether a run was finished.
 * Keep the two in step — a rule changed on one side only means the phone will
 * congratulate a run the server does not count.
 *
 * The rules:
 * - Each step needs makes_required makes in a row. A miss resets that count.
 * - A miss then applies the drill's miss rule: back to the first step (the
 *   classic ladder), down one step, or stay put.
 * - Sequential drills walk the steps in order; random drills take whichever step
 *   the phone chose, recorded on each putt.
 * - Clearing every step finishes a round; clearing drill_rounds rounds finishes
 *   the run. Anything hit after that is ignored.
 */
class DrillEngine
{
    /**
     * @param  array<int, array{made: bool, step: int|null}>  $putts  in the order they were hit
     * @return array{completed: bool, completed_at_index: int|null, round: int, step: int|null, streak: int, cleared: array<int, int>, attempts: int, furthest_step: int}
     */
    public function replay(Challenge $challenge, array $putts): array
    {
        $steps = $challenge->steps->values();
        $state = $this->start();

        foreach ($putts as $index => $putt) {
            if ($state['completed']) {
                break;
            }

            $state = $this->apply($challenge, $steps->all(), $state, $putt['made'], $putt['step']);

            if ($state['completed']) {
                $state['completed_at_index'] = $index;
            }
        }

        return $state;
    }

    /**
     * @return array{completed: bool, completed_at_index: int|null, round: int, step: int|null, streak: int, cleared: array<int, int>, attempts: int, furthest_step: int}
     */
    private function start(): array
    {
        return [
            'completed' => false,
            'completed_at_index' => null,
            'round' => 0,
            // The step the player should be on for a sequential drill.
            'step' => 0,
            'streak' => 0,
            // Steps cleared this round, by index.
            'cleared' => [],
            'attempts' => 0,
            'furthest_step' => 0,
        ];
    }

    /**
     * @param  array<int, ChallengeStep>  $steps
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function apply(Challenge $challenge, array $steps, array $state, bool $made, ?int $recordedStep): array
    {
        $count = count($steps);

        if ($count === 0) {
            return $state;
        }

        $random = $challenge->drill_order === DrillOrder::Random;
        $step = $random ? $recordedStep : $state['step'];

        // A random drill's step comes from the phone; one that is out of range or
        // already cleared cannot advance anything, so it only counts as an attempt.
        if ($step === null || $step < 0 || $step >= $count || in_array($step, $state['cleared'], true)) {
            $state['attempts']++;

            return $state;
        }

        $state['attempts']++;

        if ($made) {
            $state['streak']++;
            $required = max(1, $steps[$step]->makes_required ?? $challenge->drill_makes_required);

            if ($state['streak'] < $required) {
                return $state;
            }

            $state['streak'] = 0;
            $state['cleared'][] = $step;
            $state['furthest_step'] = max($state['furthest_step'], count($state['cleared']));
            $state['step'] = $step + 1;

            if (count($state['cleared']) < $count) {
                return $state;
            }

            // Round cleared.
            $state['round']++;
            $state['cleared'] = [];
            $state['step'] = 0;
            $state['completed'] = $state['round'] >= max(1, $challenge->drill_rounds);

            return $state;
        }

        $state['streak'] = 0;

        return match ($challenge->drill_on_miss ?? DrillMissRule::Restart) {
            DrillMissRule::Restart => [...$state, 'cleared' => [], 'step' => 0],
            DrillMissRule::Stay => $state,
            DrillMissRule::StepBack => $this->stepBack($state, $random),
        };
    }

    /**
     * Down one step. On a random drill there is no "previous" step, so the most
     * recently cleared one is reopened instead.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function stepBack(array $state, bool $random): array
    {
        if ($state['cleared'] === []) {
            return $state;
        }

        $reopened = array_pop($state['cleared']);

        if (! $random) {
            $state['step'] = $reopened;
        }

        return $state;
    }
}
