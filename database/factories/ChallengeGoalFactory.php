<?php

namespace Database\Factories;

use App\Enums\GoalMetric;
use App\Enums\GoalPeriod;
use App\Models\Challenge;
use App\Models\ChallengeGoal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChallengeGoal> */
class ChallengeGoalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'challenge_id' => Challenge::factory(),
            'metric' => GoalMetric::Attempts,
            'period' => GoalPeriod::Total,
            'target' => 2000,
            'sort_order' => 0,
        ];
    }
}
