<?php

namespace App\Actions;

use App\Enums\ChallengeKind;
use App\Models\Challenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SaveChallenge
{
    /**
     * Create or replace a challenge along with its putters, goals and steps.
     *
     * Goals and steps are rewritten wholesale rather than diffed: progress is read
     * from the putts at query time, so nothing hangs off their ids.
     *
     * @param  array<string, mixed>  $data  validated SaveChallengeRequest input
     */
    public function execute(User $user, array $data, ?Challenge $challenge = null): Challenge
    {
        return DB::transaction(function () use ($user, $data, $challenge): Challenge {
            $drill = $data['kind'] === ChallengeKind::Drill->value;

            $attributes = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'kind' => $data['kind'],
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
                // Stored as null rather than empty so "any" has exactly one spelling.
                'contexts' => ($data['contexts'] ?? []) ?: null,
                'surface_types' => ($data['surface_types'] ?? []) ?: null,
                'min_distance_ft' => $data['min_distance_ft'] ?? null,
                'max_distance_ft' => $data['max_distance_ft'] ?? null,
                'drill_on_miss' => $drill ? $data['drill_on_miss'] : null,
                'drill_order' => $drill ? $data['drill_order'] : null,
                'drill_makes_required' => $data['drill_makes_required'] ?? 1,
                'drill_rounds' => $data['drill_rounds'] ?? 1,
            ];

            if ($challenge === null) {
                $challenge = $user->challenges()->create($attributes);
            } else {
                $challenge->update($attributes);
            }

            $challenge->putters()->sync($data['putter_ids'] ?? []);

            $challenge->goals()->delete();

            foreach (array_values($data['goals'] ?? []) as $index => $goal) {
                $challenge->goals()->create([
                    'metric' => $goal['metric'],
                    'period' => $goal['period'],
                    'target' => $goal['target'],
                    'context' => $goal['context'] ?? null,
                    'min_distance_ft' => $goal['min_distance_ft'] ?? null,
                    'max_distance_ft' => $goal['max_distance_ft'] ?? null,
                    'min_attempts' => $goal['min_attempts'] ?? null,
                    'periods_required' => $goal['periods_required'] ?? null,
                    'sort_order' => $index,
                ]);
            }

            $challenge->steps()->delete();

            foreach ($drill ? array_values($data['steps'] ?? []) : [] as $index => $step) {
                $challenge->steps()->create([
                    'sort_order' => $index,
                    'distance_ft' => $step['distance_ft'],
                    'clock_position' => $step['clock_position'] ?? null,
                    'makes_required' => $step['makes_required'] ?? null,
                ]);
            }

            return $challenge->fresh(['putters', 'goals', 'steps']);
        });
    }
}
