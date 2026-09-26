<?php

namespace Database\Factories;

use App\Models\Challenge;
use App\Models\ChallengeRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** @extends Factory<ChallengeRun> */
class ChallengeRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'challenge_id' => Challenge::factory()->drill(),
            'user_id' => fn (array $attributes): int => Challenge::query()->findOrFail($attributes['challenge_id'])->user_id,
            'started_at' => Carbon::now(),
        ];
    }
}
