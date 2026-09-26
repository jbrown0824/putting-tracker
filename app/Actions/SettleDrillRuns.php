<?php

namespace App\Actions;

use App\Enums\PuttResult;
use App\Models\ChallengeRun;
use App\Services\DrillEngine;

class SettleDrillRuns
{
    public function __construct(private DrillEngine $engine) {}

    /**
     * Decide from the putts themselves whether each run was finished, and when.
     *
     * Putts can arrive late and out of order from an offline phone, so a run is
     * always replayed in full rather than advanced incrementally — the answer is
     * the same however many batches it took to arrive.
     *
     * @param  iterable<int, ChallengeRun>  $runs
     */
    public function execute(iterable $runs): void
    {
        foreach ($runs as $run) {
            $run->loadMissing('challenge.steps');

            $putts = $run->putts()->orderBy('hit_at')->orderBy('id')->get(['id', 'result', 'drill_step', 'hit_at']);

            $state = $this->engine->replay($run->challenge, $putts->map(fn ($putt): array => [
                'made' => $putt->result === PuttResult::Sunk,
                'step' => $putt->drill_step,
            ])->all());

            $run->update([
                'started_at' => $putts->first()?->hit_at ?? $run->started_at,
                'completed_at' => $state['completed'] ? $putts[$state['completed_at_index']]->hit_at : null,
            ]);
        }
    }
}
