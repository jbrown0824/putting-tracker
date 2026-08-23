<?php

namespace Database\Factories;

use App\Models\Challenge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Challenge> */
class ChallengeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => '2,000 Putt Challenge',
            'start_date' => Carbon::today(),
            'end_date' => Carbon::today()->addDays(27),
            'target_total' => 2000,
            'target_outside_min' => 400,
        ];
    }
}
