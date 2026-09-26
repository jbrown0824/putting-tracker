<?php

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\ChallengeStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChallengeStep> */
class ChallengeStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'challenge_id' => Challenge::factory()->drill(),
            'sort_order' => 0,
            'distance_ft' => 3,
            'clock_position' => null,
            'makes_required' => null,
        ];
    }
}
